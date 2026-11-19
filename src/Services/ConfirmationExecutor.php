<?php

namespace SchoolAid\Assistant\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use SchoolAid\Assistant\Contracts\PanelScope;
use SchoolAid\Assistant\Contracts\PanelSnapshot;
use SchoolAid\Assistant\Enums\AssistantConfirmationStatusEnum;
use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Enums\AssistantTurnStatusEnum;
use SchoolAid\Assistant\Jobs\RunAssistantStep;
use SchoolAid\Assistant\Models\AssistantConfirmation;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Services\Tools\AssistantToolCatalog;
use SchoolAid\Assistant\Services\Tools\AssistantUserInactive;
use SchoolAid\Assistant\Services\Tools\ToolBridge;
use SchoolAid\Assistant\Services\Tools\ToolCallResult;
use SchoolAid\Assistant\Services\Undo\UndoOffer;
use SchoolAid\Assistant\Support\AssistantActionEventMark;
use SchoolAid\Assistant\Support\ConversationLimit;
use SchoolAid\Assistant\Support\PrincipalKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * El clic de Confirmar o Rechazar de la persona: corre (o descarta) la llamada que la vista previa guardó, sin el
 * modelo. Después deja un mensaje `internal` para el agente y abre un turno de seguimiento con su primer paso en cola,
 * para que el agente siga con el pedido o lo cierre. El turno de seguimiento lleva el contexto del panel del turno
 * original: un pedido de varios pasos sigue en la sucursal que la persona tenía al pedirlo.
 *
 * Confirmar, en tres tiempos:
 * 1. Con la conversación y la confirmación bloqueadas: si no está `pending` (o ya la reclamó otra request) →
 *    {@see ConfirmationNotPending}; si venció → `expired` sin ejecutar. Si no, la reclama (`decided_at`, con el estado
 *    todavía `pending`) y suelta el bloqueo.
 * 2. Ejecuta fuera de toda transacción, dentro de {@see PanelScope::run()} con el contexto del turno: la tool
 *    hace llamadas externas (FEL, pasarelas) y despacha Jobs, que no deben quedar dentro de una transacción que
 *    podría revertirse después del efecto. `confirmationToken` va como argumento y `fromModel: false`: es el clic de
 *    la persona. Mientras corre, cada action event lleva la marca del asistente ({@see AssistantActionEventMark}).
 * 3. Con el bloqueo otra vez: `confirmed` con el `result` de la tool, o `failed` con su mensaje.
 *
 * Deshacer: tras un `confirmed` de una escritura del grupo 1, dentro del mismo contexto de la ejecución, {@see UndoOffer}
 * deja lista la vista previa de la inversa (otra confirmación `pending`, vence en `assistant.undo_minutes`); confirmarla
 * es este mismo flujo. Un deshacer nunca ofrece otro deshacer. Confirmar o rechazar con un turno en cola o en curso en
 * la conversación, o con otra confirmación suya que una request está ejecutando (un deshacer corre sin turno), responde
 * 409 {@see AssistantRequestRefused::turnInProgress()}.
 *
 * Un segundo clic llega en 1 y responde 409 sin escribir; el token es además la llave de idempotencia de la tool.
 * Cada decisión (confirmada, rechazada, vencida o fallida) cierra el turno original (`completed`) en la misma
 * transacción que crea el turno de seguimiento (R-P2-3). Si la conversación ya llegó a su fin ({@see ConversationLimit}:
 * la API no aceptaría el turno de seguimiento), Confirmar y Rechazar una vista previa responden en 1, antes de reclamar
 * nada, el mismo 409 {@see AssistantRequestRefused::conversationTooLong()} del envío de mensajes: la confirmación sigue
 * `pending` y el panel ofrece una conversación nueva. Un deshacer sí pasa (R17b): confirmarlo ejecuta la inversa y guarda
 * su resultado, pero sin turno de seguimiento (el agente ya no se puede llamar) y devuelve el turno de la escritura, ya
 * completo, como rechazar un deshacer. Si el usuario quedó dado de baja, la confirmación vuelve a `pending` sin reclamo
 * y sube {@see AssistantUserInactive}.
 */
class ConfirmationExecutor
{
    public const CONFIRMED_NOTE = '[El usuario confirmó] Resultado de %s: %s';

    public const FAILED_NOTE = '[El usuario confirmó] %s falló: %s';

    public const EXPIRED_NOTE = '[El usuario confirmó] La vista previa de %s venció (dura 10 minutos): no se ejecutó; pide una nueva si todavía hace falta.';

    public const UNDONE_NOTE = '[El usuario deshizo %s: %s] Resultado: %s';

    public const UNDO_EXPIRED_NOTE = '[Venció el Deshacer de %s; no se deshizo.]';

    public const UNDO_FAILED_NOTE = '[No se pudo deshacer %s: %s. No lo propongas otra vez salvo que la persona lo pida.]';

    public const REPLACED_BY_UNDO = 'replaced_by_undo';

    public const REJECTED_NOTE = '[El usuario rechazó la acción: %s]';

    public const EXPIRED_MESSAGE = 'La vista previa venció (dura 10 minutos): no se ejecutó.';

    public const UNDO_EXPIRED_MESSAGE = 'El plazo para deshacer venció: no se deshizo.';

    public const UNEXPECTED_FAILURE = 'La acción no terminó por un error inesperado; revisa en el panel si el cambio se hizo antes de repetirla.';

    public function __construct(
        private readonly ToolBridge $tools,
        private readonly PanelScope $scope,
        private readonly AssistantActionEventMark $mark,
        private readonly AssistantToolCatalog $catalog,
        private readonly UndoOffer $undoOffer,
        private readonly ConversationLimit $limit,
    ) {
    }

    /**
     * @return AssistantTurn el turno de seguimiento, con su primer paso en cola; el de la escritura, ya completo, si es un
     *                      deshacer en una conversación cerrada
     *
     * @throws ConfirmationNotPending
     * @throws AssistantRequestRefused hay un turno en cola o en curso, la conversación llegó a su fin, o quien confirma
     *                                 no es quien pidió la acción
     * @throws AssistantUserInactive
     */
    public function confirm(AssistantConfirmation $confirmation, Authenticatable $user): AssistantTurn
    {
        $context = $this->contextFor($confirmation, $user);

        $expired = DB::transaction(function () use ($confirmation): ?AssistantTurn {
            $locked = $this->lock($confirmation, claimed: false);

            $this->ensureNoTurnInProgress($locked);
            $this->ensureConversationOpen($locked);

            if ($locked->expires_at->isPast()) {
                $isUndo = $locked->undoes_confirmation_id !== null;

                return $this->decide(
                    $locked,
                    AssistantConfirmationStatusEnum::Expired,
                    ['message' => $isUndo ? self::UNDO_EXPIRED_MESSAGE : self::EXPIRED_MESSAGE],
                    $isUndo
                        ? sprintf(self::UNDO_EXPIRED_NOTE, $this->originalTool($locked))
                        : sprintf(self::EXPIRED_NOTE, $locked->tool),
                );
            }

            if ($locked->undoes_confirmation_id !== null) {
                $this->discardPendingPreviews($locked);
            }

            $locked->forceFill(['decided_at' => now()])->save();

            return null;
        });

        if ($expired !== null) {
            return $this->queueFirstStep($expired);
        }

        $result = $this->execute($confirmation->refresh(), $context);

        $followUp = DB::transaction(function () use ($confirmation, $result): AssistantTurn {
            $locked = $this->lock($confirmation, claimed: true);

            $original = $locked->undoes_confirmation_id === null ? null : $locked->undoes;

            if ($result->isError) {
                return $this->decide(
                    $locked,
                    AssistantConfirmationStatusEnum::Failed,
                    ['message' => $result->text],
                    $locked->undoes_confirmation_id === null
                        ? sprintf(self::FAILED_NOTE, $locked->tool, $result->text)
                        : sprintf(self::UNDO_FAILED_NOTE, $this->originalTool($locked), $result->text),
                );
            }

            $outcome = $this->outcome($result);

            return $this->decide(
                $locked,
                AssistantConfirmationStatusEnum::Confirmed,
                $outcome,
                $original === null
                    ? sprintf(self::CONFIRMED_NOTE, $locked->tool, json_encode($outcome, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    : sprintf(self::UNDONE_NOTE, $original->tool, $original->summary, json_encode($outcome, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            );
        });

        return $this->queueFirstStep($followUp);
    }

    /**
     * Rechazar un deshacer lo cierra sin ruido: ni mensaje para el agente ni turno de seguimiento (no hay nada que
     * continuar), y devuelve el turno de la escritura, ya completo.
     *
     * @return AssistantTurn el turno de seguimiento con su primer paso en cola; el de la escritura si era un deshacer
     *
     * @throws ConfirmationNotPending
     * @throws AssistantRequestRefused hay un turno en cola o en curso, la conversación llegó a su fin, o quien rechaza
     *                                 no es quien pidió la acción
     */
    public function reject(AssistantConfirmation $confirmation, Authenticatable $user): AssistantTurn
    {
        $this->contextFor($confirmation, $user);

        $followUp = DB::transaction(function () use ($confirmation): AssistantTurn {
            $locked = $this->lock($confirmation, claimed: false);

            $this->ensureNoTurnInProgress($locked);
            $this->ensureConversationOpen($locked);

            if ($locked->undoes_confirmation_id !== null) {
                $locked->forceFill([
                    'status' => AssistantConfirmationStatusEnum::Rejected,
                    'result' => ['reason' => 'rejected'],
                    'decided_at' => now(),
                ])->save();

                return AssistantTurn::query()->findOrFail($locked->turn_id);
            }

            return $this->decide(
                $locked,
                AssistantConfirmationStatusEnum::Rejected,
                ['reason' => 'rejected'],
                sprintf(self::REJECTED_NOTE, $locked->summary),
            );
        });

        return $followUp->status === AssistantTurnStatusEnum::Queued ? $this->queueFirstStep($followUp) : $followUp;
    }

    /**
     * Un turno en cola o en curso, u otra confirmación de la conversación que una request está ejecutando
     * ({@see AssistantConfirmation::scopeExecuting()}): con cualquiera de los dos, decidir esta abriría un segundo turno
     * vivo en la conversación.
     *
     * @throws AssistantRequestRefused
     */
    private function ensureNoTurnInProgress(AssistantConfirmation $locked): void
    {
        $busy = AssistantTurn::query()
            ->where('conversation_id', $locked->conversation_id)
            ->whereIn('status', [AssistantTurnStatusEnum::Queued->value, AssistantTurnStatusEnum::Running->value])
            ->exists();

        $otherExecuting = AssistantConfirmation::query()
            ->where('conversation_id', $locked->conversation_id)
            ->whereKeyNot($locked->getKey())
            ->executing()
            ->exists();

        if ($busy || $otherExecuting) {
            throw AssistantRequestRefused::turnInProgress();
        }
    }

    /**
     * La misma regla del 409 del envío de mensajes ({@see ConversationLimit::reached()}), con la conversación bloqueada:
     * en una conversación cerrada no se decide una vista previa, porque su turno de seguimiento no correría. Un deshacer
     * pasa: se decide sin seguimiento ({@see self::decide()}).
     *
     * @throws AssistantRequestRefused
     */
    private function ensureConversationOpen(AssistantConfirmation $locked): void
    {
        if ($locked->undoes_confirmation_id === null && $this->limit->reached((int) $locked->conversation_id)) {
            throw AssistantRequestRefused::conversationTooLong();
        }
    }

    /**
     * Deshacer una escritura invalida la vista previa que el agente armó encima de ella: se descarta, como cuando la
     * persona escribe en vez de confirmar (ver AssistantMessageSender).
     *
     * @throws AssistantRequestRefused una de esas vistas previas se está ejecutando
     */
    private function discardPendingPreviews(AssistantConfirmation $undo): void
    {
        $awaiting = AssistantTurn::query()
            ->where('conversation_id', $undo->conversation_id)
            ->where('status', AssistantTurnStatusEnum::AwaitingConfirmation->value)
            ->lockForUpdate()
            ->get();

        foreach ($awaiting as $turn) {
            $pending = AssistantConfirmation::query()
                ->where('turn_id', $turn->id)
                ->whereNull('undoes_confirmation_id')
                ->where('status', AssistantConfirmationStatusEnum::Pending->value)
                ->lockForUpdate()
                ->first();

            if ($pending?->decided_at !== null) {
                throw AssistantRequestRefused::turnInProgress();
            }

            $pending?->forceFill([
                'status' => AssistantConfirmationStatusEnum::Rejected,
                'result' => ['reason' => self::REPLACED_BY_UNDO],
                'decided_at' => now(),
            ])->save();

            $turn->forceFill(['status' => AssistantTurnStatusEnum::Completed, 'finished_at' => now()])->save();
        }
    }

    private function originalTool(AssistantConfirmation $undo): string
    {
        return $undo->undoes?->tool ?? $undo->tool;
    }

    /**
     * El contexto del panel guardado en el turno, si es de quien decide.
     *
     * @throws AssistantRequestRefused
     */
    private function contextFor(AssistantConfirmation $confirmation, Authenticatable $user): PanelSnapshot
    {
        try {
            $context = $this->scope->restore((array) $confirmation->turn?->panel_context);
        } catch (InvalidArgumentException) {
            throw AssistantRequestRefused::confirmationForbidden();
        }

        if ($context->principalKey() !== PrincipalKey::of($user)) {
            throw AssistantRequestRefused::confirmationForbidden();
        }

        return $context;
    }

    /**
     * Bloquea la conversación y después la confirmación, en el mismo orden que el envío de mensajes
     * ({@see AssistantMessageSender}): los dos escriben en las dos filas.
     *
     * @param  bool  $claimed  true después de ejecutar: la confirmación tiene que seguir `pending` con el reclamo
     *
     * @throws ConfirmationNotPending
     */
    private function lock(AssistantConfirmation $confirmation, bool $claimed): AssistantConfirmation
    {
        AssistantConversation::withTrashed()->whereKey($confirmation->conversation_id)->lockForUpdate()->first();

        $locked = AssistantConfirmation::query()->whereKey($confirmation->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== AssistantConfirmationStatusEnum::Pending || ($locked->decided_at !== null) !== $claimed) {
            throw new ConfirmationNotPending();
        }

        return $locked;
    }

    /**
     * @throws AssistantUserInactive
     */
    private function execute(AssistantConfirmation $confirmation, PanelSnapshot $context): ToolCallResult
    {
        $runId = (string) $confirmation->turn()->value('run_id');

        try {
            return $this->mark->during(
                (int) $confirmation->getKey(),
                $runId,
                fn (): ToolCallResult => $this->scope->run($context, function () use ($confirmation, $runId): ToolCallResult {
                    $result = $this->tools->call(
                        $confirmation->tool,
                        $confirmation->arguments + ['confirmationToken' => $confirmation->token],
                        $runId,
                        fromModel: false,
                    );

                    if (! $result->isError) {
                        $this->offerUndo($confirmation, $result, $runId);
                    }

                    return $result;
                }),
            );
        } catch (AssistantUserInactive $exception) {
            AssistantConfirmation::query()->whereKey($confirmation->getKey())->update(['decided_at' => null]);

            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return ToolCallResult::error(self::UNEXPECTED_FAILURE);
        }
    }

    /**
     * Ofrece deshacer una escritura del grupo 1 recién ejecutada. Corre antes de guardar la decisión, así que el
     * `result` se pone solo en memoria. Una falla aquí nunca tumba la confirmación: simplemente no hay deshacer.
     */
    private function offerUndo(AssistantConfirmation $confirmation, ToolCallResult $result, string $runId): void
    {
        $tool = $this->catalog->find($confirmation->tool);

        if ($confirmation->undoes_confirmation_id !== null || $tool === null || $this->catalog->groupOf($tool, $confirmation->arguments) !== 1) {
            return;
        }

        try {
            $this->undoOffer->offer((clone $confirmation)->setAttribute('result', $this->outcome($result)), $runId);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * El `result` de la respuesta `executed` de la tool; si no trae uno, su texto.
     *
     * @return array<string, mixed>
     */
    private function outcome(ToolCallResult $result): array
    {
        $executed = $result->payload['result'] ?? null;

        return is_array($executed) ? $executed : ['text' => $result->text];
    }

    /**
     * Guarda la decisión, cierra el turno original y abre el de seguimiento con el aviso para el agente. Un deshacer en
     * una conversación cerrada no abre seguimiento: devuelve el turno de la escritura, ya completo.
     *
     * @param  array<string, mixed>  $result
     */
    private function decide(AssistantConfirmation $confirmation, AssistantConfirmationStatusEnum $status, array $result, string $note): AssistantTurn
    {
        $confirmation->forceFill([
            'status' => $status,
            'result' => $result,
            'decided_at' => $confirmation->decided_at ?? now(),
        ])->save();

        $original = AssistantTurn::query()->findOrFail($confirmation->turn_id);

        if ($original->status === AssistantTurnStatusEnum::AwaitingConfirmation) {
            $original->forceFill(['status' => AssistantTurnStatusEnum::Completed, 'finished_at' => now()])->save();
        }

        if ($confirmation->undoes_confirmation_id !== null && $this->limit->reached((int) $original->conversation_id)) {
            return $original;
        }

        $followUp = AssistantTurn::query()->create([
            'conversation_id' => $original->conversation_id,
            'run_id' => 'assistant-'.Str::uuid(),
            'status' => AssistantTurnStatusEnum::Queued,
            'panel_context' => $original->panel_context,
        ]);

        AssistantMessage::query()->create([
            'conversation_id' => $original->conversation_id,
            'turn_id' => $followUp->id,
            'role' => 'user',
            'kind' => AssistantMessageKindEnum::Internal,
            'content' => [['type' => 'text', 'text' => $note]],
            'display_text' => null,
        ]);

        return $followUp;
    }

    /**
     * Solo un turno de seguimiento (`queued`): sin él (un deshacer en una conversación cerrada), el turno es el de la
     * escritura, ya completo.
     */
    private function queueFirstStep(AssistantTurn $turn): AssistantTurn
    {
        if ($turn->status === AssistantTurnStatusEnum::Queued) {
            RunAssistantStep::dispatch($turn->id);
        }

        return $turn;
    }
}
