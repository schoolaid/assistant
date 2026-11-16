<?php

namespace SchoolAid\Assistant\Services\Agent;

use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Services\Anthropic\AnthropicResponse;
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
     * Lo que se le pide al modelo al final de la conversación para que la resuma. Una app las reemplaza con
     * `assistant.summary.instructions`.
     */
    public const DEFAULT_INSTRUCTIONS = <<<'TEXT'
        Resume esta conversación para que puedas continuarla sin el historial. Escribe solo el resumen, en texto plano y en el idioma de la conversación. No llames ninguna tool y no le respondas a la persona.

        Conserva:
        - qué quiere lograr la persona y su último pedido abierto;
        - qué se consultó (reportes, registros): con qué tool y con qué argumentos o filtros, para poder repetir la consulta, y los ids de los registros de los que se habló;
        - las cifras y conclusiones clave de la última respuesta;
        - cada cambio que se propuso y cómo terminó: confirmado, rechazado, descartado o deshecho;
        - una pregunta con opciones que siga sin responder;
        - las preferencias que la persona dijo (formato, idioma, columnas).

        No incluyas saludos, ni el detalle de tablas completas, ni estas instrucciones.
        TEXT;

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

    public function instructions(): string
    {
        $own = AssistantNaming::config('summary.instructions');

        return is_string($own) && trim($own) !== '' ? $own : self::DEFAULT_INSTRUCTIONS;
    }

    /**
     * Guarda el resumen que escribió el modelo: reemplaza todo hasta `$throughMessageId`.
     *
     * Delante del resumen van, copiados tal cual de lo guardado, los dos contextos que solo existen en mensajes que el
     * resumen reemplaza y que el `system` le dice al modelo que use: quién escribe y dónde (el primer bloque del primer
     * mensaje de la conversación) y el momento y alcance del último mensaje de la persona. Un turno de seguimiento de
     * Confirmar no trae contexto propio, así que sin esto se quedaría sin ninguno. De una conversación que ya tenía
     * un resumen, salen de ese resumen. El que no exista simplemente no va.
     *
     * `model` queda en null: es un mensaje nuestro, no una respuesta de la API, y así
     * {@see self::promptTokens()} y {@see \SchoolAid\Assistant\Support\ConversationLimit} no lo toman por una. Los
     * tokens son los de la llamada de resumen, para auditarla.
     */
    public function store(AssistantTurn $turn, AnthropicResponse $response, string $summary, int $throughMessageId): AssistantMessage
    {
        $context = $this->contextBlocks((int) $turn->conversation_id, $throughMessageId);

        return AssistantMessage::query()->create([
            'conversation_id' => $turn->conversation_id,
            'turn_id' => $turn->getKey(),
            'role' => 'user',
            'kind' => AssistantMessageKindEnum::Summary,
            'content' => [
                ...array_values(array_filter($context)),
                ['type' => 'text', 'text' => self::HEADER.trim($summary)],
            ],
            'payload' => [
                'through_message_id' => $throughMessageId,
                'context' => ['conversation' => $context['conversation'] !== null, 'turn' => $context['turn'] !== null],
            ],
            'display_text' => null,
            'model' => null,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cache_read_tokens' => $response->cacheReadTokens,
            'cache_write_tokens' => $response->cacheWriteTokens,
        ]);
    }

    /**
     * Los bloques de contexto que el resumen lleva delante, de lo guardado hasta `$throughMessageId`.
     *
     * El envío de un mensaje guarda sus bloques como [contexto de la conversación (solo en el primero), contexto del
     * turno, avisos…, el texto]: se toman por posición, y solo de un mensaje de la persona con al menos un bloque más
     * detrás.
     *
     * @return array{conversation: array<string, mixed>|null, turn: array<string, mixed>|null}
     */
    private function contextBlocks(int $conversationId, int $throughMessageId): array
    {
        $messages = AssistantMessage::query()->where('conversation_id', $conversationId)->where('id', '<=', $throughMessageId);
        $previous = (clone $messages)
            ->where('kind', AssistantMessageKindEnum::Summary->value)
            ->orderByDesc('id')
            ->get()
            ->first(fn (AssistantMessage $summary): bool => self::throughMessageId($summary) !== null);

        $first = (clone $messages)->orderBy('id')->first();
        $firstId = $first?->getKey();
        $conversation = $first !== null && $this->isFromPerson($first) ? $this->textBlock($first, 0, 3) : null;
        $turn = null;

        if ($previous !== null) {
            $carried = (array) (((array) $previous->payload)['context'] ?? []);
            $blocks = array_values((array) $previous->content);
            $conversation = ($carried['conversation'] ?? false) ? ($blocks[0] ?? null) : null;
            $turn = ($carried['turn'] ?? false) ? ($blocks[($carried['conversation'] ?? false) ? 1 : 0] ?? null) : null;
        }

        $lastFromPerson = (clone $messages)
            ->where('role', 'user')
            ->where('kind', AssistantMessageKindEnum::Text->value)
            ->when($previous !== null, fn ($query) => $query->where('id', '>', $previous->getKey()))
            ->orderByDesc('id')
            ->first();

        if ($lastFromPerson !== null) {
            $opens = $lastFromPerson->getKey() === $firstId;
            $turn = $this->textBlock($lastFromPerson, $opens ? 1 : 0, $opens ? 3 : 2) ?? $turn;
        }

        return ['conversation' => $conversation, 'turn' => $turn];
    }

    private function isFromPerson(AssistantMessage $message): bool
    {
        return $message->role === 'user' && $message->kind === AssistantMessageKindEnum::Text;
    }

    /**
     * El bloque `$index` del mensaje si es de texto y el mensaje tiene al menos `$minBlocks`.
     *
     * @return array<string, mixed>|null
     */
    private function textBlock(AssistantMessage $message, int $index, int $minBlocks): ?array
    {
        $blocks = array_values((array) $message->content);
        $block = $blocks[$index] ?? null;

        return count($blocks) >= $minBlocks && is_array($block) && ($block['type'] ?? null) === 'text' ? $block : null;
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
