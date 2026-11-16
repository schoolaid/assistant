<?php

namespace SchoolAid\Assistant\Services\Agent;

use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Models\AssistantMessage;
use Illuminate\Database\Eloquent\Relations\HasMany;
use stdClass;

/**
 * La conversación guardada como `messages` de la Messages API, en cada paso.
 *
 * El historial solo se agrega: todos los mensajes, desde el primero y en orden, con `content` tal
 * como se guardó. Nada se recorta ni reescribe, tampoco los bloques `thinking` /
 * `redacted_thinking` de turnos anteriores: su firma ata el razonamiento al `system`, las `tools` y
 * los mensajes previos, y si algo de eso cambió la API los descarta (`drop_block`, ver
 * {@see AssistantTurnRunner}). Así cada paso manda el prefijo exacto del anterior (caché y firmas).
 *
 * La única excepción es el resumen de una conversación larga ({@see ConversationSummary}): si la
 * conversación tiene un `summary` logrado, el historial empieza en el más reciente y sigue con los
 * mensajes posteriores al último que ese resumen reemplaza. De lo resumido no se reenvía nada, así
 * que tampoco queda razonamiento viejo cuya firma ya no valga. Un intento fallido nunca va. Sin
 * resumen, el tope de tamaño lo pone el envío de mensajes
 * (`assistant.max_conversation_input_tokens`): el panel ofrece una conversación nueva.
 *
 * Qué no va:
 * - Los mensajes `confirmation`, `choices` y `error`: los escribió el backend para el panel, nunca
 *   la API. Una respuesta rechazada (`refusal`) se guarda como `error`: su salida parcial se
 *   descarta. La pregunta de un `choices` ya va en el `tool_use` de `offer-choices` que la pidió.
 *
 * Lo único que cambia respecto de lo guardado, porque la API lo exige (todo determinístico: cada
 * paso lo hace igual sobre lo mismo):
 * - Mensajes vecinos del mismo rol se juntan en uno, en el orden guardado de sus bloques (la API
 *   exige alternar `user`/`assistant`): p. ej. el texto de la persona tras un mensaje de
 *   `tool_result`, o lo que queda a los lados de un `confirmation`, `choices` o `error` que no va.
 * - El `input` vacío de un `tool_use` va como objeto JSON (`{}`): al decodificar la respuesta queda
 *   `[]`, y la API rechaza un `input` que no sea objeto.
 * - Los bloques `text` vacíos no van (la API los rechaza), y un mensaje que queda sin bloques
 *   tampoco.
 * - Un `server_tool_use` que nunca tuvo resultado (el turno terminó o falló justo después de un
 *   `pause_turn`) no va, salvo mientras sigue abierto: en el último mensaje (la API retoma la
 *   pausa) o en el penúltimo seguido de un mensaje `user` solo con `tool_result` (la API lo corre
 *   en esa llamada). En cualquier otro lugar la API rechaza la conversación (doc «Handle tool
 *   calls»: con un server tool sin resultado, el mensaje siguiente lleva solo `tool_result`). Si al
 *   quitarlo el mensaje queda solo con bloques `thinking`, tampoco va el mensaje.
 * - Un `tool_use` que quedó sin `tool_result` (el turno falló a medias) recibe uno de error:
 *   sin él, la API rechaza la conversación entera.
 *
 * Los demás `server_tool_use` y los `tool_search_tool_result` de la búsqueda de tools van tal cual:
 * nunca llevan `tool_result` nuestro.
 */
class ConversationTranscript
{
    public const INTERRUPTED_RESULT = 'No se ejecutó: el pedido se interrumpió antes de terminar.';

    /**
     * Los escribe el backend para el panel y no van a la API.
     */
    private const PANEL_ONLY_KINDS = [AssistantMessageKindEnum::Confirmation, AssistantMessageKindEnum::Choices, AssistantMessageKindEnum::Chart, AssistantMessageKindEnum::File, AssistantMessageKindEnum::Error];

    public function __construct(private readonly ConversationSummary $summary)
    {
    }

    private const THINKING_TYPES = ['thinking', 'redacted_thinking'];

    /**
     * Con `$beforeMessageId`, solo lo anterior a ese mensaje: es lo que se le manda al resumidor.
     *
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    public function toApiMessages(AssistantConversation $conversation, ?int $beforeMessageId = null): array
    {
        $summary = $this->summary->latest($conversation, $beforeMessageId);
        $stored = $summary === null ? [] : [['role' => $summary->role, 'content' => $this->storedBlocks($summary->content)]];

        $messages = $this->sentToModel($conversation)
            ->when($summary !== null, fn ($query) => $query->where('id', '>', ConversationSummary::throughMessageId($summary)))
            ->when($beforeMessageId !== null, fn ($query) => $query->where('id', '<', $beforeMessageId))
            ->orderBy('id')
            ->get();

        foreach ($messages as $message) {
            $stored[] = ['role' => $message->role, 'content' => $this->storedBlocks($message->content)];
        }

        $messages = $this->mergeNeighbours($this->withoutClosedServerToolUses($this->mergeNeighbours($stored), $beforeMessageId === null));

        return $this->answerDanglingToolUses($messages);
    }

    /**
     * Junta vecinos del mismo rol (bloques en orden) y quita los mensajes sin bloques.
     *
     * @param  list<array{role: string, content: list<array<string, mixed>>}>  $messages
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    private function mergeNeighbours(array $messages): array
    {
        $merged = [];

        foreach ($messages as $message) {
            if ($message['content'] === []) {
                continue;
            }

            $last = array_key_last($merged);

            if ($last !== null && $merged[$last]['role'] === $message['role']) {
                $merged[$last]['content'] = [...$merged[$last]['content'], ...$message['content']];
            } else {
                $merged[] = $message;
            }
        }

        return $merged;
    }

    /**
     * Quita los `server_tool_use` que nunca tuvieron resultado (ningún bloque posterior lo nombra
     * en `tool_use_id`) salvo donde siguen abiertos (ver la doc de la clase). Con `$mayBeOpen` en
     * false ninguno sigue abierto: es un historial cortado para el resumidor, que lleva detrás las
     * instrucciones de resumen y no un turno que la API vaya a retomar.
     *
     * @param  list<array{role: string, content: list<array<string, mixed>>}>  $messages
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    private function withoutClosedServerToolUses(array $messages, bool $mayBeOpen = true): array
    {
        $resolved = [];

        foreach ($messages as $message) {
            foreach ($message['content'] as $block) {
                if (is_string($block['tool_use_id'] ?? null)) {
                    $resolved[$block['tool_use_id']] = true;
                }
            }
        }

        foreach ($messages as $index => $message) {
            if ($message['role'] !== 'assistant' || ($mayBeOpen && $this->isOpenServerTurn($messages, $index))) {
                continue;
            }

            $kept = array_values(array_filter(
                $message['content'],
                fn (array $block): bool => ($block['type'] ?? null) !== 'server_tool_use' || isset($resolved[(string) ($block['id'] ?? '')]),
            ));

            $messages[$index]['content'] = count($kept) < count($message['content']) && $this->onlyThinking($kept) ? [] : $kept;
        }

        return $messages;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function onlyThinking(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (! in_array($block['type'] ?? null, self::THINKING_TYPES, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{role: string, content: list<array<string, mixed>>}>  $messages
     */
    private function isOpenServerTurn(array $messages, int $index): bool
    {
        $last = array_key_last($messages);

        if ($index === $last) {
            return true;
        }

        if ($index !== $last - 1 || $messages[$last]['role'] !== 'user') {
            return false;
        }

        foreach ($messages[$last]['content'] as $block) {
            if (($block['type'] ?? null) !== 'tool_result') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return HasMany<AssistantMessage>
     */
    private function sentToModel(AssistantConversation $conversation): HasMany
    {
        return $conversation->messages()->whereNotIn(
            'kind',
            array_map(fn (AssistantMessageKindEnum $kind): string => $kind->value, [...self::PANEL_ONLY_KINDS, AssistantMessageKindEnum::Summary]),
        );
    }

    /**
     * Los bloques guardados, en orden, sin los `text` vacíos y con el `input` vacío como objeto.
     *
     * @param  array<int|string, mixed>  $content
     * @return list<array<string, mixed>>
     */
    private function storedBlocks(array $content): array
    {
        $blocks = [];

        foreach ($content as $block) {
            if (! is_array($block) || $this->isEmptyText($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'tool_use' && ($block['input'] ?? []) === []) {
                $block['input'] = new stdClass();
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function isEmptyText(array $block): bool
    {
        return ($block['type'] ?? null) === 'text' && trim((string) ($block['text'] ?? '')) === '';
    }

    /**
     * @param  list<array{role: string, content: list<array<string, mixed>>}>  $messages
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    private function answerDanglingToolUses(array $messages): array
    {
        for ($index = 0; $index < count($messages); $index++) {
            if ($messages[$index]['role'] !== 'assistant') {
                continue;
            }

            $next = $messages[$index + 1] ?? null;
            $answered = $next === null ? [] : $this->blockIds($next['content'], 'tool_result', 'tool_use_id');
            $missing = array_values(array_diff($this->blockIds($messages[$index]['content'], 'tool_use', 'id'), $answered));

            if ($missing === []) {
                continue;
            }

            $results = array_map(fn (string $id): array => [
                'type' => 'tool_result',
                'tool_use_id' => $id,
                'content' => self::INTERRUPTED_RESULT,
                'is_error' => true,
            ], $missing);

            if ($next === null) {
                $messages[] = ['role' => 'user', 'content' => $results];
            } else {
                $messages[$index + 1]['content'] = [...$results, ...$next['content']];
            }
        }

        return $messages;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<string>
     */
    private function blockIds(array $blocks, string $type, string $key): array
    {
        $ids = [];

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === $type && is_string($block[$key] ?? null)) {
                $ids[] = $block[$key];
            }
        }

        return $ids;
    }
}
