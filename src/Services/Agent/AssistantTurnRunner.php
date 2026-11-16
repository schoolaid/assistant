<?php

namespace SchoolAid\Assistant\Services\Agent;

use SchoolAid\Assistant\Support\AssistantNaming;
use SchoolAid\Assistant\Contracts\Instructions;
use SchoolAid\Assistant\Contracts\PanelScope;
use SchoolAid\Assistant\Contracts\WritePreviews;
use SchoolAid\Assistant\Enums\AssistantConfirmationStatusEnum;
use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Enums\AssistantTurnStatusEnum;
use SchoolAid\Assistant\Enums\StepOutcomeEnum;
use SchoolAid\Assistant\Models\AssistantConfirmation;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Services\Anthropic\AnthropicApiKeyRejected;
use SchoolAid\Assistant\Services\Anthropic\AnthropicMessagesClient;
use SchoolAid\Assistant\Services\Anthropic\AnthropicRequestFailed;
use SchoolAid\Assistant\Services\Anthropic\AnthropicResponse;
use SchoolAid\Assistant\Services\Tools\AssistantUserInactive;
use SchoolAid\Assistant\Services\Exports\AssistantExportRequester;
use SchoolAid\Assistant\Services\Tools\ToolBridge;
use SchoolAid\Assistant\Jobs\BuildAssistantExport;
use SchoolAid\Assistant\Models\AssistantExport;
use SchoolAid\Assistant\Services\Tools\ToolCallResult;
use SchoolAid\Assistant\Services\Usage\AssistantBudget;
use SchoolAid\Assistant\Support\ConversationLimit;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * Un turno se corre por pasos: cada {@see self::step()} es una llamada a la Messages API más sus
 * tools (un Job de la cola `assistant`), y el paso siguiente arranca de lo guardado.
 *
 * 1. Reclama el paso con un update atómico: turno `queued`/`running` sin paso en curso, o con uno
 *    reclamado hace más de {@see self::STALE_STEP_SECONDS} (su worker murió). Si no lo reclama,
 *    `Skipped`. Un turno `queued` pasa a `running` aquí.
 * 2. Corre dentro de {@see PanelContextScope::run()} con el contexto guardado en el turno: las tools
 *    ven el usuario y la sucursal que la persona tenía al enviar el mensaje. Antes de llamar la API
 *    mira lo guardado ({@see self::resumeFromStored()}): una confirmación pendiente, unos
 *    `tool_use` sin resultados o una respuesta final se retoman sin volver a llamarla. Si no, llama
 *    la API con la conversación entera ({@see ConversationTranscript}) y guarda la respuesta cruda.
 *    Si el turno empieza con la conversación sobre `assistant.summarize_at_input_tokens`, este paso es el de resumen
 *    ({@see self::summarize()}): guarda un mensaje `summary` y el paso siguiente hace la llamada normal sobre el
 *    historial ya resumido. No cuenta en `iterations`.
 * 3. Según `stop_reason`: `end_turn` termina; `tool_use` corre los `tool_use` en orden y guarda un
 *    solo mensaje `user` interno con todos los `tool_result`; lo primero que llegue entre una vista
 *    previa de escritura (crea la confirmación: el turno espera el clic) y un `offer-choices`
 *    válido (guarda un mensaje `choices` y el turno termina) corta el turno, y los `tool_use`
 *    siguientes se contestan sin ejecutar. `offer-choices` es local ({@see OfferChoicesTool}): nunca
 *    pasa por el puente. `show-chart` también es local ({@see ShowChartTool}), pero no corta el turno: guarda un
 *    mensaje `chart` después de los resultados y el paso sigue. `export-file` ({@see ExportFileTool}) igual: valida y
 *    lee la primera página ({@see AssistantExportRequester}), guarda el archivo `pending` con su mensaje `file` y
 *    encola el Job que lo arma; `export-document` ({@see ExportDocumentTool}) lo mismo, con el markdown del modelo y sin
 *    leer nada. `max_tokens` no corre un `tool_use` cortado; `refusal` termina con un aviso;
 *    `pause_turn` (la búsqueda de tools corre en el servidor) sigue en el paso siguiente con la
 *    respuesta tal cual. Solo se ejecutan los `tool_use`: `server_tool_use` y
 *    `tool_search_tool_result` se guardan y se reenvían sin tocar.
 * 4. El cierre es atómico ({@see self::closeStep()}): los mensajes finales del paso, la
 *    confirmación, `iterations`++ (también si el paso falló), `step_started_at` = null y el estado
 *    del turno van en una sola transacción, condicionada al reclamo. Al llegar a
 *    `assistant.max_iterations` con trabajo pendiente, termina explicando lo que alcanzó.
 *
 * Una escritura nunca se ejecuta aquí: el modelo solo consigue una vista previa, y el token de la
 * confirmación lo usa el clic de Confirmar de la persona.
 */
class AssistantTurnRunner
{
    public const FAILED_MESSAGE = 'No pude completar el pedido. Intenta de nuevo en un momento.';

    public const INACTIVE_USER_MESSAGE = 'Tu usuario está inactivo: no puedo seguir con este pedido.';

    public const LIMIT_MESSAGE = 'Llegué al límite de pasos de este pedido; esto es lo que alcancé a hacer: ';

    public const TOO_LONG_MESSAGE = 'La respuesta salió demasiado larga; pide algo más acotado.';

    public const REFUSAL_MESSAGE = 'No puedo ayudar con ese pedido.';

    public const API_KEY_REJECTED_MESSAGE = 'La llave de API propia dejó de funcionar: quien administra el asistente tiene que cambiarla o quitarla en la configuración.';

    public const PENDING_CONFIRMATION_RESULT = 'No ejecutado: hay una confirmación pendiente.';

    public const PENDING_CHOICE_RESULT = 'No ejecutado: hay una pregunta pendiente.';

    public const CUT_RESULT = 'No ejecutado: la respuesta se cortó.';

    /**
     * Un paso reclamado hace más de esto se da por muerto: el Job tiene `timeout` 200 s y la cola
     * `retry_after` 240 s.
     */
    public const STALE_STEP_SECONDS = 210;

    /**
     * Headers `anthropic-beta` de cada llamada (ver {@see self::payload()}).
     */
    public const BETAS = ['thinking-binding-controls-2026-08-01', 'server-side-fallback-2026-07-01'];

    /**
     * La tool de búsqueda del servidor: nunca diferida (la API da 400 si todo está diferido).
     */
    public const TOOL_SEARCH_TOOL = ['type' => 'tool_search_tool_bm25_20251119', 'name' => 'tool_search_tool_bm25'];

    private const EMPTY_RESULT = '(sin resultado)';

    /**
     * Argumentos con los que se confirma una escritura: la confirmación guarda la llamada sin ellos.
     */
    private const CONFIRMATION_ARGUMENTS = ['confirmationToken', 'idempotencyKey'];

    private const MAX_ERROR_CHARS = 2000;

    /**
     * Largo de `assistant_messages.stop_reason`.
     */
    private const MAX_STOP_REASON_CHARS = 32;

    public function __construct(
        private readonly AnthropicMessagesClient $client,
        private readonly AssistantBudget $budget,
        private readonly ToolBridge $tools,
        private readonly PanelScope $scope,
        private readonly Instructions $instructions,
        private readonly ConversationTranscript $transcript,
        private readonly AssistantExportRequester $exports,
        private readonly WritePreviews $previews,
        private readonly ConversationSummary $summary,
    ) {
    }

    /**
     * Reclamado el paso, nunca lanza: una falla deja un mensaje `error` visible y el turno
     * `failed`. Si el reclamo se pierde a medio paso (otro worker lo retomó), no guarda nada y
     * devuelve `Skipped`. Solo una falla de la base al reclamar se propaga (el Job falla y el
     * recolector lo retoma).
     *
     * Si la API rechaza el pedido (400, 413), repetirlo con el mismo historial daría lo mismo: el
     * mensaje es {@see ConversationLimit::MESSAGE} y el `error` del turno lleva
     * {@see ConversationLimit::DEAD_END_ERROR_PREFIX}, con lo que la conversación no admite otro
     * mensaje. Cualquier otra falla (5xx, 429, sin conexión, ya con el reintento corto del cliente)
     * deja {@see self::FAILED_MESSAGE}. Si Anthropic rechaza la llave propia del market
     * ({@see AnthropicApiKeyRejected}), {@see self::API_KEY_REJECTED_MESSAGE}: no se reporta, lo arregla el market.
     */
    public function step(AssistantTurn $turn): StepOutcomeEnum
    {
        if (! $this->claim($turn)) {
            return StepOutcomeEnum::Skipped;
        }

        $turn->refresh();

        try {
            if ($turn->started_at === null) {
                $turn->forceFill(['started_at' => now()])->save();
            }

            return $this->scope->run(
                $this->scope->restore((array) $turn->panel_context),
                fn (): StepOutcomeEnum => $this->runStep($turn),
            );
        } catch (StepClaimLost) {
            return StepOutcomeEnum::Skipped;
        } catch (AssistantUserInactive $exception) {
            return $this->failStep($turn, $exception, self::INACTIVE_USER_MESSAGE);
        } catch (AnthropicApiKeyRejected $exception) {
            return $this->failStep($turn, $exception, self::API_KEY_REJECTED_MESSAGE);
        } catch (AnthropicRequestFailed $exception) {
            report($exception);

            return $exception->exceedsRequestLimits()
                ? $this->failStep($turn, $exception, ConversationLimit::MESSAGE, ConversationLimit::DEAD_END_ERROR_PREFIX)
                : $this->failStep($turn, $exception, self::FAILED_MESSAGE);
        } catch (Throwable $exception) {
            report($exception);

            return $this->failStep($turn, $exception, self::FAILED_MESSAGE);
        }
    }

    private function claim(AssistantTurn $turn): bool
    {
        $claimed = AssistantTurn::query()
            ->whereKey($turn->getKey())
            ->whereIn('status', [AssistantTurnStatusEnum::Queued->value, AssistantTurnStatusEnum::Running->value])
            ->where(fn (Builder $query) => $query
                ->whereNull('step_started_at')
                ->orWhere('step_started_at', '<=', now()->subSeconds(self::STALE_STEP_SECONDS)))
            ->update(['status' => AssistantTurnStatusEnum::Running->value, 'step_started_at' => now()->startOfSecond()]);

        return $claimed === 1;
    }

    private function runStep(AssistantTurn $turn): StepOutcomeEnum
    {
        $resumed = $this->resumeFromStored($turn);

        if ($resumed !== null) {
            return $resumed;
        }

        if ($this->summary->due($turn)) {
            return $this->summarize($turn);
        }

        $messages = $this->transcript->toApiMessages($turn->conversation);
        $response = $this->client->create($this->payload($turn, $messages), self::BETAS);
        $this->budget->record($response, (int) ($turn->panel_context['user_id'] ?? 0) ?: null);

        return match ($response->stopReason) {
            'end_turn' => $this->closeStep($turn, StepOutcomeEnum::Completed, fn () => $this->storeResponse($turn, $response)),
            'pause_turn' => $this->closeStep($turn, StepOutcomeEnum::Continue, fn () => $this->storeResponse($turn, $response), paused: true),
            'tool_use' => $this->runRequestedTools($turn, $response),
            'max_tokens' => $this->closeStep($turn, StepOutcomeEnum::Completed, fn () => $this->storeCutResponse($turn, $response)),
            'refusal' => $this->closeStep($turn, StepOutcomeEnum::Completed, fn () => $this->storeRefusal($turn, $response)),
            default => $this->unexpectedStop($turn, $response),
        };
    }

    /**
     * El paso de resumen de un turno que empieza con la conversación sobre `assistant.summarize_at_input_tokens`
     * ({@see ConversationSummary}): le pide al modelo que resuma todo lo anterior a este turno y lo guarda como un
     * mensaje `summary`. El paso siguiente hace la llamada normal, ya sobre el historial resumido.
     *
     * El pedido es el de siempre ({@see self::payload()}: mismo `model`, `system`, `tools`, `thinking`, `output_config`
     * y `cache_control`) con las instrucciones de resumen al final, así lee de la caché lo que dejó el turno anterior y
     * los bloques `thinking` del historial siguen valiendo en esta llamada. No lleva `tool_choice`: cambiarlo invalida
     * la caché de los mensajes; que no llame tools se lo piden las instrucciones. Solo cambia `max_tokens`.
     *
     * No cuenta como iteración del turno: no es trabajo del pedido.
     */
    private function summarize(AssistantTurn $turn): StepOutcomeEnum
    {
        $conversation = $turn->conversation;
        $firstOfTurn = (int) AssistantMessage::query()->where('turn_id', $turn->id)->min('id');
        $through = (int) AssistantMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', '<', $firstOfTurn)
            ->max('id');

        $messages = $this->withSummaryRequest($this->transcript->toApiMessages($conversation, $firstOfTurn));
        $payload = [...$this->payload($turn, $messages), 'max_tokens' => $this->summary->maxTokens()];

        try {
            $response = $this->client->create($payload, self::BETAS);
        } catch (AnthropicRequestFailed $failure) {
            if ($failure->status !== 400 && ! $failure->exceedsRequestLimits()) {
                throw $failure;
            }

            report($failure);

            return $this->summaryFailed($turn, 'rejected');
        }

        $this->budget->record($response, (int) ($turn->panel_context['user_id'] ?? 0) ?: null);
        $summary = trim($response->text());

        if ($response->stopReason === 'end_turn' && $summary !== '') {
            return $this->closeStep(
                $turn,
                StepOutcomeEnum::Continue,
                fn () => $this->summary->store($turn, $response, $summary, $through),
                counts: false,
            );
        }

        return $this->summaryFailed($turn, $response->stopReason === 'end_turn' ? 'empty' : $response->stopReason, $response);
    }

    /**
     * El modelo no dejó un resumen (rechazo, corte por `max_tokens`, pidió una tool, respondió sin texto) o la API
     * rechazó el pedido (un 400 cualquiera o un 413: repetirlo daría lo mismo, y el pedido normal que sigue todavía
     * puede servir). El turno no se detiene: queda la marca del intento y el paso siguiente hace la llamada normal con
     * el historial completo, como si no se resumiera. Si la respuesta pidió tools, no se ejecutan: esa respuesta no se
     * guarda como mensaje.
     *
     * Un rechazo de los clasificadores solo va al log. Lo demás es cosa nuestra (un tope de salida corto, unas
     * instrucciones que el modelo no sigue) y se reporta: bajo el tope de tamaño el resumen se reintenta y se paga
     * en cada turno, y eso no puede quedar solo en un log.
     *
     * Cualquier otro fallo de la API (429, 5xx, sin conexión) no llega aquí: sube a {@see self::step()}, el turno
     * falla como cualquier llamada y no queda marca, así que el mensaje siguiente lo intenta de nuevo.
     */
    private function summaryFailed(AssistantTurn $turn, string $reason, ?AnthropicResponse $response = null): StepOutcomeEnum
    {
        Log::warning('Assistant could not summarize a conversation; the turn goes on with the whole history.', [
            'conversation_id' => $turn->conversation_id,
            'turn_id' => $turn->id,
            'reason' => $reason,
        ]);

        if (! in_array($reason, ['refusal', 'rejected'], true)) {
            report(new UnexpectedValueException("The summary of conversation {$turn->conversation_id} (turn {$turn->id}) ended with {$reason}."));
        }

        return $this->closeStep(
            $turn,
            StepOutcomeEnum::Continue,
            fn () => $this->summary->storeFailed($turn, Str::limit($reason, self::MAX_STOP_REASON_CHARS, ''), $response),
            counts: false,
        );
    }

    /**
     * La conversación con las instrucciones de resumen al final, en un mensaje `user` (dentro del último si ya lo es:
     * la API exige alternar).
     *
     * @param  list<array{role: string, content: list<array<string, mixed>>}>  $messages
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    private function withSummaryRequest(array $messages): array
    {
        $request = ['type' => 'text', 'text' => $this->summary->instructions()];
        $last = array_key_last($messages);

        if ($last !== null && $messages[$last]['role'] === 'user') {
            $messages[$last]['content'][] = $request;

            return $messages;
        }

        return [...$messages, ['role' => 'user', 'content' => [$request]]];
    }

    /**
     * Lo guardado ya dice cómo sigue el turno, según el último mensaje del turno y el
     * `stop_reason` guardado con cada respuesta:
     *
     * - Una confirmación pendiente: el turno espera el clic (sin llamar la API). Un deshacer pendiente no cuenta: se
     *   ofrece después de que el turno terminó y nunca lo detiene.
     * - El último es `user` (el pedido, unos resultados de tools) o una respuesta `pause_turn`: se
     *   llama la API; a una pausa se le reenvía la conversación tal cual y la API la retoma.
     * - Una respuesta `tool_use` cuyos `tool_use` no tienen resultados (van siempre en el mensaje
     *   siguiente): se corren esas tools sin llamar la API. El paso anterior murió después de
     *   guardarla. Una respuesta sin `stop_reason` (guardada antes de la columna) con `tool_use`
     *   sin resultados también: un `max_tokens` guarda sus resultados en la misma transacción.
     * - Cualquier otro mensaje `assistant` es final (`end_turn`, aviso de `refusal`, `max_tokens` o
     *   tope de pasos, confirmación ya decidida): el turno terminó, sin llamar la API. Con el
     *   cierre atómico solo se llega aquí si el cierre del paso no se guardó.
     *
     * Los mensajes `chart` y `file` no cuentan: van después de los resultados de las tools y el turno sigue.
     */
    private function resumeFromStored(AssistantTurn $turn): ?StepOutcomeEnum
    {
        $awaitsConfirmation = $turn->confirmation()
            ->whereNull('undoes_confirmation_id')
            ->where('status', AssistantConfirmationStatusEnum::Pending->value)
            ->exists();

        if ($awaitsConfirmation) {
            return $this->closeStep($turn, StepOutcomeEnum::AwaitingConfirmation);
        }

        $last = $turn->messages()
            ->whereNotIn('kind', [AssistantMessageKindEnum::Chart->value, AssistantMessageKindEnum::File->value])
            ->orderByDesc('id')
            ->first();

        if ($last === null || $last->role !== 'assistant' || $last->stop_reason === 'pause_turn') {
            return null;
        }

        $unanswered = $last->kind === AssistantMessageKindEnum::Text && in_array($last->stop_reason, ['tool_use', null], true)
            ? $this->toolUses($last->content)
            : [];

        if ($unanswered !== []) {
            return $this->answerToolUses($turn, $unanswered);
        }

        return $this->closeStep($turn, StepOutcomeEnum::Completed);
    }

    /**
     * Cuerpo de POST /v1/messages. El historial solo se agrega: `system` es el guardado en la
     * conversación y `tools` van ordenadas por `name`, así cada paso repite el prefijo del anterior.
     *
     * Nombres de campos y headers copiados de la documentación oficial de Anthropic, leída el
     * 2026-11-09 (skill `claude-api` 2.1.319 y platform.claude.com/docs):
     * - `thinking: {type: adaptive, block_binding: {prefix_mismatch_behavior: drop_block}}` con el
     *   header `thinking-binding-controls-2026-08-01`: build-with-claude/preserved-thinking y la
     *   guía de migración «Migrating to Claude Sonnet 5.5 → Breaking change 3». Sin el header, la
     *   API rechaza `block_binding`. Con `drop_block`, si el prefijo de un bloque `thinking`
     *   guardado cambió (tools nuevas por permisos, p. ej.), la API lo descarta en vez de dar 400.
     * - `output_config: {effort}`: GA, sin header (skill, «Thinking & Effort»). En Sonnet 5.5 el
     *   default es `high`; se fija explícito con `assistant.effort`.
     * - `cache_control` en el nivel superior = caché automática del final de la conversación,
     *   junto con el marcador explícito del `system` (skill, prompt-caching «Automatic vs explicit
     *   breakpoints»: la combinación recomendada para loops de agente). Ninguna tool lleva
     *   `cache_control`: el marcador del `system` ya cubre las tools (van antes) y una tool con
     *   `defer_loading` no puede llevarlo (400; agents-and-tools/tool-use/tool-search-tool).
     *   El prefijo que cubre ese marcador (`tools` + `system`) no lleva nada de la persona ni del
     *   market ({@see AssistantInstructions::system()}): lo leen de la caché todas las
     *   conversaciones con el mismo conjunto de tools. Su duración es `assistant.prefix_cache_ttl`
     *   ({@see self::prefixCacheControl()}); la cola de la conversación queda en 5 minutos (un
     *   marcador de 1 hora antes de uno de 5 minutos es el orden que la API acepta).
     * - `fallbacks: "default"` con el header `server-side-fallback-2026-07-01`:
     *   build-with-claude/refusals-and-fallback. Ante un rechazo de los clasificadores la API
     *   reintenta en el modelo que recomienda; la respuesta trae el `model` que respondió y un
     *   bloque `fallback` que se guarda y se reenvía en su lugar.
     * - Búsqueda de tools: `{type: tool_search_tool_bm25_20251119, name: tool_search_tool_bm25}`,
     *   sin header, nunca diferida (agents-and-tools/tool-use/tool-search-tool).
     *
     * @param  list<array{role: string, content: list<array<string, mixed>>}>  $messages
     * @return array<string, mixed>
     */
    private function payload(AssistantTurn $turn, array $messages): array
    {
        $payload = [
            'model' => (string) AssistantNaming::config('model'),
            'max_tokens' => (int) AssistantNaming::config('max_tokens'),
            'thinking' => ['type' => 'adaptive', 'block_binding' => ['prefix_mismatch_behavior' => 'drop_block']],
            'output_config' => ['effort' => (string) AssistantNaming::config('effort')],
            'cache_control' => ['type' => 'ephemeral'],
            'fallbacks' => 'default',
            'system' => [['type' => 'text', 'text' => $this->systemPrompt($turn), 'cache_control' => $this->prefixCacheControl()]],
            'messages' => $messages,
        ];

        $payload['tools'] = $this->toolDefinitions();

        return $payload;
    }

    /**
     * Marcador de caché del prefijo fijo. Con `assistant.prefix_cache_ttl` en `1h` la escritura
     * cuesta 2 x la entrada en vez de 1.25 x, y a cambio una pausa de 5 a 60 minutos (o el primer
     * mensaje de otra persona con las mismas tools) lee el prefijo en vez de escribirlo otra vez.
     * Cualquier otro valor deja los 5 minutos por omisión de la API.
     *
     * @return array{type: string, ttl?: string}
     */
    private function prefixCacheControl(): array
    {
        return AssistantNaming::config('prefix_cache_ttl') === '1h'
            ? ['type' => 'ephemeral', 'ttl' => '1h']
            : ['type' => 'ephemeral'];
    }

    /**
     * Las instrucciones de la conversación tal como se guardaron: no se recalculan dentro de una
     * conversación (un `system` que cambia invalida la caché y las firmas del historial). Se llenan
     * aquí solo si el envío del mensaje no las fijó.
     */
    private function systemPrompt(AssistantTurn $turn): string
    {
        $conversation = $turn->conversation;

        if ((string) $conversation->system_prompt === '') {
            $conversation->forceFill(['system_prompt' => $this->instructions->system()])->save();
        }

        return (string) $conversation->system_prompt;
    }

    /**
     * Las tools del usuario, `offer-choices`, `show-chart` y `export-file` (sin diferir) y, con `assistant.tool_search.enabled`,
     * la de búsqueda; todas ordenadas juntas por `name`. Como `offer-choices` va siempre, `tools`
     * nunca queda vacío: un request con bloques `tool_use`/`tool_result` y sin `tools` es un 400,
     * aunque el usuario haya perdido todos sus permisos.
     *
     * @return list<array<string, mixed>>
     */
    private function toolDefinitions(): array
    {
        $tools = [...array_values($this->tools->definitions()), OfferChoicesTool::definition(), ShowChartTool::definition(), ExportFileTool::definition(), ExportDocumentTool::definition()];

        if ((bool) AssistantNaming::config('tool_search.enabled', true)) {
            $tools[] = self::TOOL_SEARCH_TOOL;
        }

        usort($tools, fn (array $first, array $second): int => strcmp((string) $first['name'], (string) $second['name']));

        return $tools;
    }

    /**
     * La respuesta se guarda antes de correr las tools, si el paso sigue siendo de este worker: si
     * muere en medio, el siguiente las corre desde lo guardado sin volver a llamar la API.
     */
    private function runRequestedTools(AssistantTurn $turn, AnthropicResponse $response): StepOutcomeEnum
    {
        $message = DB::transaction(function () use ($turn, $response): AssistantMessage {
            $this->assertClaimHeld($turn);

            return $this->storeResponse($turn, $response);
        });

        $toolUses = $this->toolUses($message->content);

        return $toolUses === [] ? $this->closeStep($turn, StepOutcomeEnum::Completed) : $this->answerToolUses($turn, $toolUses);
    }

    /**
     * Un `tool_use` cortado puede traer el `input` incompleto: se contesta sin ejecutar.
     */
    private function storeCutResponse(AssistantTurn $turn, AnthropicResponse $response): void
    {
        $message = $this->storeResponse($turn, $response);
        $results = array_map(
            fn (array $toolUse): array => $this->toolResult($toolUse['id'], self::CUT_RESULT, true),
            $this->toolUses($message->content),
        );

        if ($results !== []) {
            $this->storeMessage($turn, 'user', AssistantMessageKindEnum::Internal, $results);
        }

        $this->storeVisible($turn, AssistantMessageKindEnum::Error, self::TOO_LONG_MESSAGE);
    }

    /**
     * Lo rechazado no se trata como respuesta del modelo (la doc de rechazos: descartar la salida
     * parcial): queda como un solo mensaje `error`, con los bloques crudos y el consumo para
     * auditar y el aviso como texto visible. Los `error` no van a la API.
     */
    private function storeRefusal(AssistantTurn $turn, AnthropicResponse $response): void
    {
        $this->storeResponse($turn, $response, AssistantMessageKindEnum::Error, self::REFUSAL_MESSAGE);
    }

    private function unexpectedStop(AssistantTurn $turn, AnthropicResponse $response): StepOutcomeEnum
    {
        $exception = new UnexpectedValueException('Unexpected stop_reason from the Messages API: '.$response->stopReason);
        report($exception);

        return $this->closeStep(
            $turn,
            StepOutcomeEnum::Failed,
            function () use ($turn, $response): void {
                $this->storeResponse($turn, $response);
                $this->storeVisible($turn, AssistantMessageKindEnum::Error, self::FAILED_MESSAGE);
            },
            ['error' => $this->errorText($exception)],
        );
    }

    /**
     * Corre los `tool_use` en orden y cierra el paso con sus resultados en un solo mensaje. Desde la
     * primera vista previa o el primer `offer-choices` válido, los siguientes se contestan sin
     * ejecutar: con la vista previa el turno espera la confirmación; con las opciones, termina con
     * el mensaje `choices` (la elección llega como el siguiente mensaje). Un `offer-choices` inválido
     * vuelve al modelo como error con el motivo y el paso sigue, para que lo corrija. Es igual al
     * correr la respuesta recién llegada y al retomarla desde lo guardado.
     *
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $toolUses
     */
    private function answerToolUses(AssistantTurn $turn, array $toolUses): StepOutcomeEnum
    {
        $results = [];
        $preview = null;
        $choices = null;
        $charts = [];
        $exports = [];

        foreach ($toolUses as $toolUse) {
            if ($preview !== null || $choices !== null) {
                $results[] = $this->toolResult($toolUse['id'], $preview !== null ? self::PENDING_CONFIRMATION_RESULT : self::PENDING_CHOICE_RESULT, true);

                continue;
            }

            if ($toolUse['name'] === ShowChartTool::NAME) {
                $chart = ShowChartTool::validate($toolUse['input']);
                $results[] = is_string($chart)
                    ? $this->toolResult($toolUse['id'], $chart, true)
                    : $this->toolResult($toolUse['id'], ShowChartTool::SHOWN_RESULT, false);

                if (! is_string($chart)) {
                    $charts[] = $chart;
                }

                continue;
            }

            if ($toolUse['name'] === ExportFileTool::NAME) {
                $requested = $this->exports->request($turn, $toolUse['input'], count($exports));
                $results[] = $this->toolResult($toolUse['id'], $requested['result'], $requested['isError']);

                if ($requested['export'] !== null) {
                    $exports[] = $requested['export'];
                }

                continue;
            }

            if ($toolUse['name'] === ExportDocumentTool::NAME) {
                $requested = $this->exports->requestDocument($turn, $toolUse['input'], count($exports));
                $results[] = $this->toolResult($toolUse['id'], $requested['result'], $requested['isError']);

                if ($requested['export'] !== null) {
                    $exports[] = $requested['export'];
                }

                continue;
            }

            if ($toolUse['name'] === OfferChoicesTool::NAME) {
                $offered = OfferChoicesTool::validate($toolUse['input']);
                $results[] = is_string($offered)
                    ? $this->toolResult($toolUse['id'], $offered, true)
                    : $this->toolResult($toolUse['id'], OfferChoicesTool::OFFERED_RESULT, false);
                $choices = is_string($offered) ? null : $offered;

                continue;
            }

            $result = $this->tools->call($toolUse['name'], $toolUse['input'], $turn->run_id);
            $results[] = $this->toolResult($toolUse['id'], $result->text, $result->isError);

            if ($result->isPreview && $result->confirmationToken !== null) {
                $preview = [
                    'tool' => $toolUse['name'],
                    'arguments' => Arr::except($toolUse['input'], self::CONFIRMATION_ARGUMENTS),
                    'result' => $result,
                ];
            }
        }

        $outcome = match (true) {
            $preview !== null => StepOutcomeEnum::AwaitingConfirmation,
            $choices !== null => StepOutcomeEnum::Completed,
            default => StepOutcomeEnum::Continue,
        };

        return $this->closeStep(
            $turn,
            $outcome,
            function () use ($turn, $results, $preview, $choices, $charts, $exports): void {
                $this->storeMessage($turn, 'user', AssistantMessageKindEnum::Internal, $results);

                foreach ($charts as $chart) {
                    $this->storeChart($turn, $chart);
                }

                foreach ($exports as $export) {
                    $this->storeExport($turn, $export);
                }

                if ($preview !== null) {
                    $this->awaitConfirmation($turn, $preview);
                }

                if ($choices !== null) {
                    $this->storeChoices($turn, $choices);
                }
            },
        );
    }

    /**
     * La pregunta con opciones queda como mensaje `choices` (solo del panel, no va a la API): el
     * panel la muestra con botones y la elección llega como el siguiente mensaje de la persona.
     *
     * @param  array{question: string, options: list<array{id: string, label: string, detail: string|null, selectable: bool}>}  $choices
     */
    private function storeChoices(AssistantTurn $turn, array $choices): void
    {
        $this->storeMessage(
            $turn,
            'assistant',
            AssistantMessageKindEnum::Choices,
            [['type' => 'text', 'text' => $choices['question']]],
            $choices['question'],
            ['choices' => $choices],
        );
    }

    /**
     * La gráfica queda como mensaje `chart` (solo del panel, no va a la API) con el spec normalizado en `payload`; el
     * título es su `display_text`. El turno sigue: el modelo cierra con su texto.
     *
     * @param  array<string, mixed>  $chart
     */
    private function storeChart(AssistantTurn $turn, array $chart): void
    {
        $this->storeMessage(
            $turn,
            'assistant',
            AssistantMessageKindEnum::Chart,
            [['type' => 'text', 'text' => $chart['title']]],
            $chart['title'],
            ['payload' => ['chart' => $chart]],
        );
    }

    /**
     * El archivo pedido queda `pending` con su mensaje `file` (solo del panel; `payload.export_id`). El Job sale al
     * confirmar la transacción del cierre: si el cierre no se guarda, no hay archivo huérfano.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function storeExport(AssistantTurn $turn, array $attributes): void
    {
        $export = AssistantExport::query()->create($attributes);

        $this->storeMessage(
            $turn,
            'assistant',
            AssistantMessageKindEnum::File,
            [['type' => 'text', 'text' => $export->title]],
            $export->title,
            ['payload' => ['export_id' => $export->id]],
        );

        BuildAssistantExport::dispatch($export->id)->afterCommit();
    }

    /**
     * @return array<string, mixed>
     */
    private function toolResult(string $toolUseId, string $text, bool $isError): array
    {
        $block = [
            'type' => 'tool_result',
            'tool_use_id' => $toolUseId,
            'content' => $text !== '' ? $text : self::EMPTY_RESULT,
        ];

        if ($isError) {
            $block['is_error'] = true;
        }

        return $block;
    }

    /**
     * La vista previa queda como mensaje `confirmation` con su resumen (solo del panel) y como
     * confirmación pendiente, sin token ni llave de idempotencia en los argumentos: el Confirmar
     * los agrega.
     *
     * @param  array{tool: string, arguments: array<string, mixed>, result: ToolCallResult}  $preview
     */
    private function awaitConfirmation(AssistantTurn $turn, array $preview): void
    {
        $result = $preview['result'];
        $summary = $result->summary !== null && trim($result->summary) !== '' ? $result->summary : 'Vista previa de '.$preview['tool'];
        $message = $this->storeVisible($turn, AssistantMessageKindEnum::Confirmation, $summary);

        AssistantConfirmation::query()->create([
            'turn_id' => $turn->id,
            'conversation_id' => $turn->conversation_id,
            'message_id' => $message->id,
            'tool' => $preview['tool'],
            'arguments' => $preview['arguments'],
            'summary' => $summary,
            'preview' => is_array($result->payload['preview'] ?? null) ? $result->payload['preview'] : [],
            'token' => $result->confirmationToken,
            'expires_at' => $this->expiresAt($result->expiresAt),
            'status' => AssistantConfirmationStatusEnum::Pending,
        ]);
    }

    private function expiresAt(?string $expiresAt): CarbonInterface
    {
        if ($expiresAt === null) {
            return now()->addSeconds($this->previews->ttlSeconds());
        }

        return Carbon::parse($expiresAt)->setTimezone((string) config('app.timezone'));
    }

    /**
     * Los `tool_use` (tools del cliente) de unos bloques guardados; los `server_tool_use` corren en
     * el servidor y no se tocan.
     *
     * @param  array<int|string, mixed>  $content
     * @return list<array{id: string, name: string, input: array<string, mixed>}>
     */
    private function toolUses(array $content): array
    {
        $toolUses = [];

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use') {
                $toolUses[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'name' => (string) ($block['name'] ?? ''),
                    'input' => (array) ($block['input'] ?? []),
                ];
            }
        }

        return $toolUses;
    }

    /**
     * Cierra el paso en una transacción: lo que el paso guarda al final (`$writes`), el texto del
     * tope de pasos si toca, `iterations`++, `step_started_at` = null y el estado del turno. El
     * update del turno va condicionado al reclamo que tomó este worker: si otro lo retomó (o el
     * recolector lo soltó), {@see StepClaimLost} revierte todo.
     *
     * Un paso que terminó en `pause_turn` tiene un paso más de margen: con el tope en ese paso, el
     * texto del tope quedaría después de un `server_tool_use` sin resultado.
     *
     * @param  (Closure(): mixed)|null  $writes
     * @param  array<string, mixed>  $attributes
     */
    private function closeStep(AssistantTurn $turn, StepOutcomeEnum $outcome, ?Closure $writes = null, array $attributes = [], bool $paused = false, bool $counts = true): StepOutcomeEnum
    {
        return DB::transaction(function () use ($turn, $outcome, $writes, $attributes, $paused, $counts): StepOutcomeEnum {
            $this->assertClaimHeld($turn);

            if ($writes !== null) {
                $writes();
            }

            $iterations = $turn->iterations + ($counts ? 1 : 0);
            $maxIterations = max(1, (int) AssistantNaming::config('max_iterations'));

            if ($counts && $outcome === StepOutcomeEnum::Continue && $iterations >= $maxIterations + ($paused ? 1 : 0)) {
                $this->storeVisible($turn, AssistantMessageKindEnum::Text, $this->limitText($turn));
                $outcome = StepOutcomeEnum::Completed;
            }

            $values = [...$attributes, 'iterations' => $iterations, 'step_started_at' => null];
            $status = $this->finalStatus($outcome);

            if ($status !== null) {
                $values['status'] = $status->value;
                $values['finished_at'] = now();
            }

            $updated = AssistantTurn::query()
                ->whereKey($turn->getKey())
                ->where('step_started_at', $turn->step_started_at)
                ->update($values);

            if ($updated !== 1) {
                throw new StepClaimLost((int) $turn->getKey());
            }

            $turn->forceFill($values)->syncOriginal();

            return $outcome;
        });
    }

    /**
     * Bloquea la fila del turno y verifica que el reclamo sigue siendo de este worker.
     *
     * @throws StepClaimLost
     */
    private function assertClaimHeld(AssistantTurn $turn): void
    {
        $held = AssistantTurn::query()
            ->whereKey($turn->getKey())
            ->where('step_started_at', $turn->step_started_at)
            ->lockForUpdate()
            ->exists();

        if (! $held) {
            throw new StepClaimLost((int) $turn->getKey());
        }
    }

    private function finalStatus(StepOutcomeEnum $outcome): ?AssistantTurnStatusEnum
    {
        return match ($outcome) {
            StepOutcomeEnum::AwaitingConfirmation => AssistantTurnStatusEnum::AwaitingConfirmation,
            StepOutcomeEnum::Completed => AssistantTurnStatusEnum::Completed,
            StepOutcomeEnum::Failed => AssistantTurnStatusEnum::Failed,
            StepOutcomeEnum::Continue, StepOutcomeEnum::Skipped => null,
        };
    }

    /**
     * Lo que el modelo dijo en el turno o, si no dijo nada, las tools que alcanzó a pedir.
     */
    private function limitText(AssistantTurn $turn): string
    {
        $texts = [];
        $toolNames = [];
        $responses = $turn->messages()
            ->where('role', 'assistant')
            ->where('kind', AssistantMessageKindEnum::Text->value)
            ->orderBy('id')
            ->get();

        foreach ($responses as $response) {
            if (trim((string) $response->display_text) !== '') {
                $texts[] = trim((string) $response->display_text);
            }

            foreach ($this->toolUses($response->content) as $toolUse) {
                $toolNames[] = $toolUse['name'];
            }
        }

        $done = match (true) {
            $texts !== [] => implode("\n\n", $texts),
            $toolNames !== [] => 'usé '.implode(', ', array_values(array_unique($toolNames))).'.',
            default => 'nada todavía.',
        };

        return self::LIMIT_MESSAGE.$done;
    }

    private function storeResponse(
        AssistantTurn $turn,
        AnthropicResponse $response,
        AssistantMessageKindEnum $kind = AssistantMessageKindEnum::Text,
        ?string $displayText = null,
    ): AssistantMessage {
        return AssistantMessage::query()->create([
            'conversation_id' => $turn->conversation_id,
            'turn_id' => $turn->id,
            'role' => 'assistant',
            'kind' => $kind,
            'content' => $response->content,
            'display_text' => $displayText ?? $response->text(),
            'model' => $response->model !== '' ? $response->model : (string) AssistantNaming::config('model'),
            'stop_reason' => $response->stopReason !== '' ? Str::limit($response->stopReason, self::MAX_STOP_REASON_CHARS, '') : null,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cache_read_tokens' => $response->cacheReadTokens,
            'cache_write_tokens' => $response->cacheWriteTokens,
        ]);
    }

    /**
     * Un mensaje `assistant` que escribe el backend (aviso, error, resumen de vista previa).
     */
    private function storeVisible(AssistantTurn $turn, AssistantMessageKindEnum $kind, string $text): AssistantMessage
    {
        return $this->storeMessage($turn, 'assistant', $kind, [['type' => 'text', 'text' => $text]], $text);
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $attributes
     */
    private function storeMessage(AssistantTurn $turn, string $role, AssistantMessageKindEnum $kind, array $content, ?string $displayText = null, array $attributes = []): AssistantMessage
    {
        return AssistantMessage::query()->create([
            'conversation_id' => $turn->conversation_id,
            'turn_id' => $turn->id,
            'role' => $role,
            'kind' => $kind,
            'content' => $content,
            'display_text' => $displayText,
            ...$attributes,
        ]);
    }

    /**
     * El mensaje visible y el estado `failed` van en la misma transacción del cierre: quien hace
     * poll ve `failed` con su mensaje ya guardado. Si el cierre falla, se reporta y el recolector
     * retoma el paso reclamado.
     *
     * @param  string  $errorPrefix  marca al inicio del `error` del turno (ver {@see ConversationLimit})
     */
    private function failStep(AssistantTurn $turn, Throwable $exception, string $visibleMessage, string $errorPrefix = ''): StepOutcomeEnum
    {
        try {
            return $this->closeStep(
                $turn,
                StepOutcomeEnum::Failed,
                fn () => $this->storeVisible($turn, AssistantMessageKindEnum::Error, $visibleMessage),
                ['error' => $errorPrefix.$this->errorText($exception)],
            );
        } catch (StepClaimLost) {
            return StepOutcomeEnum::Skipped;
        } catch (Throwable $closeFailure) {
            report($closeFailure);

            return StepOutcomeEnum::Failed;
        }
    }

    private function errorText(Throwable $exception): string
    {
        return Str::limit($exception::class.': '.$exception->getMessage(), self::MAX_ERROR_CHARS, '');
    }
}
