<?php

namespace SchoolAid\Assistant\Services;

use SchoolAid\Assistant\Contracts\Instructions;
use SchoolAid\Assistant\Contracts\PanelScope;
use SchoolAid\Assistant\Enums\AssistantConfirmationStatusEnum;
use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Enums\AssistantTurnStatusEnum;
use SchoolAid\Assistant\Jobs\RunAssistantStep;
use SchoolAid\Assistant\Models\AssistantConfirmation;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Services\Settings\AssistantSettings;
use SchoolAid\Assistant\Services\Usage\AssistantBudget;
use SchoolAid\Assistant\Services\Usage\AssistantBudgetExceeded;
use SchoolAid\Assistant\Support\ConversationLimit;
use Illuminate\Contracts\Auth\Authenticatable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lo que pasa cuando la persona escribe en una conversación: abre un turno y despacha su primer paso.
 *
 * Antes, en este orden: el tope del mes del market y el de la persona ({@see AssistantBudget}), la llave propia del
 * market rechazada por Anthropic (409 `assistant_api_key_invalid`, {@see AssistantSettings::ownKeyRejected()}), un paso en cola o en curso o una confirmación de la
 * conversación que otra request está ejecutando (409, ver {@see AssistantConfirmation::scopeExecuting()}: un deshacer
 * corre sin turno en cola ni en curso) y el fin de la conversación (409 `assistant_conversation_too_long`, ver
 * {@see ConversationLimit}: la última respuesta llegó al tope de entrada o la API rechazó el último turno con un 400 o
 * un 413; el historial solo se agrega y no se recorta, así que el panel ofrece una conversación nueva).
 *
 * Después, en una transacción con la conversación bloqueada (dos envíos a la vez no abren dos turnos):
 * - Un turno que espera su confirmación se cierra (`completed`): escribir en vez de confirmar es el «Cambiar algo» de
 *   la página, así que la vista previa pasa a `rejected` con `{reason: replaced_by_message}` y el mensaje nuevo lo
 *   avisa al agente. Si esa confirmación se está ejecutando ahora (otra request la reclamó), 409; si el reclamo es más
 *   viejo que el candado del token ({@see \SchoolAid\Assistant\Contracts\WritePreviews::lockSeconds()}, el tope de una request), la ejecución
 *   murió a medias: queda `failed` con {@see self::INTERRUPTED_RESULT} y el mensaje lo avisa. Un reclamo así de un
 *   deshacer lo cierra el recolector. Un deshacer (confirmación con `undoes_confirmation_id`) nunca es la vista previa
 *   de un turno: escribir no lo descarta.
 * - En el primer turno se fijan las instrucciones de la conversación (`system_prompt`); no se recalculan después.
 * - El mensaje `user` guarda en `content` el contexto del pedido (fecha, hora y sucursal), los avisos y el texto, y en
 *   `display_text` lo que el panel muestra. El primer mensaje de la conversación lleva antes quién escribe y los
 *   clientes del market ({@see Instructions::conversationContext()}): no van en `system_prompt`, que es
 *   igual para todos y por eso se lee de la caché entre usuarios.
 * - Una elección ({@see self::sendChoice()}) es un mensaje más: contesta la última pregunta con opciones (`choices`)
 *   sin un mensaje `user` posterior, con una opción `selectable`; si no, 422 `assistant_choice_not_available`. Se
 *   revisa con la conversación bloqueada, después de los 409.
 * - El turno guarda su `run_id` y el contexto del panel de la sesión (usuario y sucursal), con el que corren sus tools.
 */
class AssistantMessageSender
{
    public const REPLACED_NOTICE = '[La persona escribió en vez de confirmar; la vista previa de %s se descartó.]';

    public const INTERRUPTED_NOTICE = '[La confirmación de %s se interrumpió: puede que el cambio se haya hecho; revísalo antes de repetirlo.]';

    /**
     * El `result` de una confirmación cuya ejecución murió a medias (`failed`, con su `decided_at`). También lo pone el
     * recolector en un deshacer reclamado de más ({@see \SchoolAid\Assistant\Console\ResumeStaleTurnsCommand}).
     */
    public const INTERRUPTED_RESULT = ['reason' => 'interrupted'];

    /**
     * Lo que el modelo lee de una elección: el label y el id de la opción.
     */
    public const CHOICE_TEXT = 'Elegí: %s (id %s)';

    public function __construct(
        private readonly AssistantBudget $budget,
        private readonly Instructions $instructions,
        private readonly ConversationLimit $limit,
        private readonly AssistantSettings $settings,
        private readonly PanelScope $scope,
    ) {
    }

    /**
     * @param  string  $text  lo que va al modelo después del contexto
     * @param  string|null  $displayText  lo que el panel muestra; por omisión, `$text`
     *
     * @throws AssistantBudgetExceeded
     * @throws AssistantRequestRefused
     */
    public function send(AssistantConversation $conversation, Authenticatable $user, string $text, ?string $displayText = null): AssistantTurn
    {
        return $this->openTurn($conversation, $user, fn (): array => [$text, $displayText ?? $text]);
    }

    /**
     * La persona eligió con un botón: el modelo lee «Elegí: <label> (id <id>)» y el panel muestra el label.
     *
     * @throws AssistantBudgetExceeded
     * @throws AssistantRequestRefused
     */
    public function sendChoice(AssistantConversation $conversation, Authenticatable $user, string $choiceId): AssistantTurn
    {
        return $this->openTurn($conversation, $user, function (AssistantConversation $locked) use ($choiceId): array {
            $option = $this->availableOption($locked, $choiceId);

            return [sprintf(self::CHOICE_TEXT, $option['label'], $option['id']), $option['label']];
        });
    }

    /**
     * @param  Closure(AssistantConversation): array{0: string, 1: string}  $message  el texto para el modelo y el del panel, con la conversación bloqueada
     *
     * @throws AssistantBudgetExceeded
     * @throws AssistantRequestRefused
     */
    private function openTurn(AssistantConversation $conversation, Authenticatable $user, Closure $message): AssistantTurn
    {
        $this->budget->ensureWithinBudget((int) $user->getKey());

        if ($this->settings->ownKeyRejected()) {
            throw AssistantRequestRefused::apiKeyInvalid();
        }

        $context = $this->scope->capture($user);

        $turn = DB::transaction(function () use ($conversation, $user, $message, $context): AssistantTurn {
            $locked = AssistantConversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->turns()->whereIn('status', [AssistantTurnStatusEnum::Queued->value, AssistantTurnStatusEnum::Running->value])->exists()) {
                throw AssistantRequestRefused::turnInProgress();
            }

            if (AssistantConfirmation::query()->where('conversation_id', $locked->getKey())->executing()->exists()) {
                throw AssistantRequestRefused::turnInProgress();
            }

            if ($this->limit->reached((int) $locked->getKey())) {
                throw AssistantRequestRefused::conversationTooLong();
            }

            [$text, $displayText] = $message($locked);
            $notices = $this->closeAwaitingTurns($locked);

            $opensConversation = $locked->messages()->doesntExist();

            if ((string) $locked->system_prompt === '') {
                $locked->forceFill(['system_prompt' => $this->instructions->system()])->save();
            }

            $turn = AssistantTurn::query()->create([
                'conversation_id' => $locked->id,
                'run_id' => 'assistant-'.Str::uuid(),
                'status' => AssistantTurnStatusEnum::Queued,
                'panel_context' => $context->toArray(),
            ]);

            $blocks = array_map(
                fn (string $block): array => ['type' => 'text', 'text' => $block],
                [
                    ...($opensConversation ? [$this->instructions->conversationContext($user)] : []),
                    $this->instructions->turnContext($context, $user, now()),
                    ...$notices,
                    $text,
                ],
            );

            AssistantMessage::query()->create([
                'conversation_id' => $locked->id,
                'turn_id' => $turn->id,
                'role' => 'user',
                'kind' => AssistantMessageKindEnum::Text,
                'content' => $blocks,
                'display_text' => $displayText,
            ]);

            return $turn;
        });

        RunAssistantStep::dispatch($turn->id);

        return $turn;
    }

    /**
     * La opción de la última pregunta con opciones de la conversación, si nadie la contestó todavía (ningún mensaje
     * `user` después) y se puede elegir.
     *
     * @return array{id: string, label: string}
     *
     * @throws AssistantRequestRefused
     */
    private function availableOption(AssistantConversation $conversation, string $choiceId): array
    {
        $asked = $conversation->messages()
            ->where('kind', AssistantMessageKindEnum::Choices->value)
            ->orderByDesc('id')
            ->first();

        $answered = $asked !== null && $conversation->messages()->where('role', 'user')->where('id', '>', $asked->id)->exists();

        foreach ($asked === null || $answered ? [] : (array) ($asked->choices['options'] ?? []) as $option) {
            if (is_array($option) && (string) ($option['id'] ?? '') === $choiceId && ($option['selectable'] ?? true) === true) {
                return ['id' => (string) $option['id'], 'label' => (string) ($option['label'] ?? '')];
            }
        }

        throw AssistantRequestRefused::choiceNotAvailable();
    }

    /**
     * Cierra los turnos que esperan su confirmación y devuelve los avisos para el agente.
     *
     * @return list<string>
     *
     * @throws AssistantRequestRefused
     */
    private function closeAwaitingTurns(AssistantConversation $conversation): array
    {
        $notices = [];
        $awaiting = $conversation->turns()
            ->where('status', AssistantTurnStatusEnum::AwaitingConfirmation->value)
            ->lockForUpdate()
            ->get();

        foreach ($awaiting as $turn) {
            $confirmation = AssistantConfirmation::query()
                ->where('turn_id', $turn->id)
                ->whereNull('undoes_confirmation_id')
                ->where('status', AssistantConfirmationStatusEnum::Pending->value)
                ->lockForUpdate()
                ->first();

            if ($confirmation !== null) {
                $notices[] = $this->discard($confirmation);
            }

            $turn->forceFill(['status' => AssistantTurnStatusEnum::Completed, 'finished_at' => now()])->save();
        }

        return $notices;
    }

    /**
     * @throws AssistantRequestRefused
     */
    private function discard(AssistantConfirmation $confirmation): string
    {
        if ($confirmation->decided_at === null) {
            $confirmation->forceFill([
                'status' => AssistantConfirmationStatusEnum::Rejected,
                'result' => ['reason' => 'replaced_by_message'],
                'decided_at' => now(),
            ])->save();

            return sprintf(self::REPLACED_NOTICE, $confirmation->tool);
        }

        if ($confirmation->isExecuting()) {
            throw AssistantRequestRefused::turnInProgress();
        }

        $confirmation->forceFill([
            'status' => AssistantConfirmationStatusEnum::Failed,
            'result' => self::INTERRUPTED_RESULT,
        ])->save();

        return sprintf(self::INTERRUPTED_NOTICE, $confirmation->tool);
    }
}
