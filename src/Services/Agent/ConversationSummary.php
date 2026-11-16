<?php

namespace SchoolAid\Assistant\Services\Agent;

use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Support\AssistantNaming;

/**
 * El resumen de una conversación larga: cuándo toca escribirlo y qué hay guardado de él.
 *
 * Una conversación que pasó de `assistant.summarize_at_input_tokens` se resume al empezar su turno siguiente, en vez de
 * cerrarse: el resumen se guarda como un mensaje `summary` y {@see ConversationTranscript} arma el historial a partir
 * del último que se logró. Con la clave en null (o cualquier valor que no sea un número positivo) no se resume y el
 * motor se comporta como si esta clase no existiera.
 *
 * Una fila `summary` es de dos tipos, según su `payload`:
 * - lograda: `{through_message_id}`, el id del último mensaje que el resumen reemplaza;
 * - fallida: `{failed: true, reason}`, sin `content`. Nunca va al modelo: deja dicho que ese turno ya lo intentó.
 *
 * No recibe nada en su constructor: {@see \SchoolAid\Assistant\Support\ConversationLimit} depende de ella, nunca al
 * revés.
 */
final class ConversationSummary
{
    /**
     * Lo primero que el modelo lee de un resumen: qué es y qué no puede sacar de él.
     */
    public const HEADER = "[Resumen de la conversación]\nLo anterior de esta conversación se reemplazó por este resumen para que quepa. La persona sigue viendo en su pantalla todos sus mensajes anteriores. Si necesitas una cifra, un listado o un registro exacto de antes, vuelve a consultarlo con las tools: no lo cites de este resumen.\n\n";

    /**
     * El tamaño desde el que se resume, o null si está apagado.
     */
    public function threshold(): ?int
    {
        $value = AssistantNaming::config('summarize_at_input_tokens');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Lo que midió el último pedido a la API de la conversación (entrada, lectura y escritura de caché): es lo que
     * mediría el siguiente. Cero si todavía no hay ninguna respuesta.
     */
    public function promptTokens(int $conversationId): int
    {
        $last = AssistantMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->whereNotNull('model')
            ->orderByDesc('id')
            ->first(['id', 'input_tokens', 'cache_read_tokens', 'cache_write_tokens']);

        return $last === null ? 0 : $last->input_tokens + $last->cache_read_tokens + $last->cache_write_tokens;
    }

    /**
     * Si este turno tiene que resumir antes de responder: solo al empezar (sin ninguna respuesta de la API todavía),
     * una vez por turno, y con la conversación sobre el umbral. A mitad de un turno hay `tool_use` esperando su
     * `tool_result`, y un resumen ahí rompería la ronda.
     */
    public function due(AssistantTurn $turn): bool
    {
        $threshold = $this->threshold();

        if ($threshold === null || $this->attemptedIn($turn)) {
            return false;
        }

        $answered = AssistantMessage::query()
            ->where('turn_id', $turn->getKey())
            ->where('role', 'assistant')
            ->whereNotNull('model')
            ->exists();

        return ! $answered && $this->promptTokens((int) $turn->conversation_id) >= $threshold;
    }

    /**
     * El turno ya tiene su fila `summary`, lograda o fallida.
     */
    public function attemptedIn(AssistantTurn $turn): bool
    {
        return AssistantMessage::query()
            ->where('turn_id', $turn->getKey())
            ->where('kind', AssistantMessageKindEnum::Summary->value)
            ->exists();
    }

    /**
     * El último resumen logrado de la conversación; con `$beforeMessageId`, el último anterior a ese mensaje.
     */
    public function latest(AssistantConversation $conversation, ?int $beforeMessageId = null): ?AssistantMessage
    {
        return AssistantMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('kind', AssistantMessageKindEnum::Summary->value)
            ->when($beforeMessageId !== null, fn ($query) => $query->where('id', '<', $beforeMessageId))
            ->orderByDesc('id')
            ->get()
            ->first(fn (AssistantMessage $summary): bool => self::throughMessageId($summary) !== null);
    }

    /**
     * Si el último intento de resumen de cada conversación falló (una sin intentos: no).
     *
     * @param  list<int>  $conversationIds
     * @return array<int, bool> por id de conversación
     */
    public function lastAttemptFailedEach(array $conversationIds): array
    {
        $conversationIds = array_values(array_unique(array_map('intval', $conversationIds)));
        $failed = array_fill_keys($conversationIds, false);

        if ($conversationIds === []) {
            return $failed;
        }

        $lastIds = AssistantMessage::query()
            ->selectRaw('MAX(id)')
            ->whereIn('conversation_id', $conversationIds)
            ->where('kind', AssistantMessageKindEnum::Summary->value)
            ->groupBy('conversation_id');

        foreach (AssistantMessage::query()->whereIn('id', $lastIds)->get(['id', 'conversation_id', 'payload']) as $attempt) {
            $failed[(int) $attempt->conversation_id] = self::throughMessageId($attempt) === null;
        }

        return $failed;
    }

    /**
     * El id del último mensaje que un resumen logrado reemplaza; null en un intento fallido.
     */
    public static function throughMessageId(AssistantMessage $summary): ?int
    {
        $through = ((array) $summary->payload)['through_message_id'] ?? null;

        return is_numeric($through) ? (int) $through : null;
    }
}
