<?php

namespace SchoolAid\Assistant\Support;

use SchoolAid\Assistant\Enums\AssistantTurnStatusEnum;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Services\Agent\ConversationSummary;

/**
 * Cuándo una conversación ya no admite otro turno: el panel ofrece una conversación nueva.
 *
 * - La última respuesta de la API llegó a `assistant.max_conversation_input_tokens` sumando la entrada y la caché (es
 *   lo que mide la siguiente llamada). **Con el resumen encendido** (`assistant.summarize_at_input_tokens`,
 *   {@see ConversationSummary}) eso solo cierra la conversación si además su último intento de resumen falló: una
 *   conversación sobre el tope que todavía no lo intentó no está cerrada, porque su turno siguiente resume primero.
 *   Con el resumen apagado el historial nunca se recorta y el tope cierra por sí solo.
 * - El último turno murió porque la API rechazó el pedido con un 400 o un 413: su `error` empieza con
 *   {@see self::DEAD_END_ERROR_PREFIX} y repetir el mismo historial daría lo mismo.
 *
 * Es la única regla detrás del 409 `assistant_conversation_too_long`: la usan el envío de mensajes, Confirmar /
 * Rechazar una vista previa (antes de reclamar la confirmación: decidirla abriría un turno que la API no aceptaría; un
 * deshacer se decide sin turno de seguimiento) y el `closed` de la conversación en el panel. {@see self::reached()} es
 * {@see self::reachedEach()} con una sola conversación: el listado resuelve su página entera con las mismas consultas.
 */
class ConversationLimit
{
    /**
     * Marca en `assistant_turns.error` del turno cuya llamada la API rechazó con 400 o 413.
     */
    public const DEAD_END_ERROR_PREFIX = '[conversation_rejected_by_api] ';

    /**
     * El mensaje `error` visible cuando la API rechazó el turno y la conversación llegó a su fin.
     */
    public const MESSAGE = 'Esta conversación ya no se puede continuar; empieza una nueva.';

    public function __construct(private readonly ConversationSummary $summary)
    {
    }

    public function reached(int $conversationId): bool
    {
        return $this->reachedEach([$conversationId])[$conversationId];
    }

    /**
     * Si cada conversación llegó a su fin, con las mismas consultas para todas.
     *
     * @param  list<int>  $conversationIds
     * @return array<int, bool> por id de conversación
     */
    public function reachedEach(array $conversationIds): array
    {
        $conversationIds = array_values(array_unique(array_map('intval', $conversationIds)));

        if ($conversationIds === []) {
            return [];
        }

        $lastResponses = $this->lastResponses($conversationIds);
        $lastTurns = $this->lastTurns($conversationIds);
        $summaryFailed = $this->summary->threshold() === null ? null : $this->summary->lastAttemptFailedEach($conversationIds);
        $reached = [];

        foreach ($conversationIds as $conversationId) {
            $tooLong = $this->isTooLong($lastResponses[$conversationId] ?? null)
                && ($summaryFailed === null || $summaryFailed[$conversationId]);

            $reached[$conversationId] = $tooLong || $this->wasRejectedByApi($lastTurns[$conversationId] ?? null);
        }

        return $reached;
    }

    /**
     * La última respuesta de la API de cada conversación (la de id más alto con `model`), por id de conversación.
     *
     * @param  list<int>  $conversationIds
     * @return array<int, AssistantMessage>
     */
    private function lastResponses(array $conversationIds): array
    {
        $lastIds = AssistantMessage::query()
            ->selectRaw('MAX(id)')
            ->whereIn('conversation_id', $conversationIds)
            ->where('role', 'assistant')
            ->whereNotNull('model')
            ->groupBy('conversation_id');

        return AssistantMessage::query()
            ->whereIn('id', $lastIds)
            ->get(['id', 'conversation_id', 'input_tokens', 'cache_read_tokens', 'cache_write_tokens'])
            ->keyBy('conversation_id')
            ->all();
    }

    /**
     * El último turno de cada conversación, por id de conversación.
     *
     * @param  list<int>  $conversationIds
     * @return array<int, AssistantTurn>
     */
    private function lastTurns(array $conversationIds): array
    {
        $lastIds = AssistantTurn::query()
            ->selectRaw('MAX(id)')
            ->whereIn('conversation_id', $conversationIds)
            ->groupBy('conversation_id');

        return AssistantTurn::query()
            ->whereIn('id', $lastIds)
            ->get(['id', 'conversation_id', 'status', 'error'])
            ->keyBy('conversation_id')
            ->all();
    }

    /**
     * Entrada, lectura y escritura de caché de la última respuesta de la API.
     */
    private function isTooLong(?AssistantMessage $last): bool
    {
        if ($last === null) {
            return false;
        }

        $promptTokens = $last->input_tokens + $last->cache_read_tokens + $last->cache_write_tokens;

        return $promptTokens >= (int) AssistantNaming::config('max_conversation_input_tokens');
    }

    private function wasRejectedByApi(?AssistantTurn $last): bool
    {
        return $last !== null
            && $last->status === AssistantTurnStatusEnum::Failed
            && str_starts_with((string) $last->error, self::DEAD_END_ERROR_PREFIX);
    }
}
