<?php

namespace SchoolAid\Assistant\Services\Entities;

use SchoolAid\Assistant\Support\AssistantNaming;
use SchoolAid\Assistant\Enums\AssistantConfirmationStatusEnum;
use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Models\AssistantConfirmation;
use SchoolAid\Assistant\Models\AssistantConversation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qué registros salieron en una conversación, para que el panel los enlace (spec, «Salida»): los que el agente leyó
 * con una tool y los que tocaron o crearon sus escrituras confirmadas. Sin columna propia: sale de los mensajes
 * guardados (como {@see \SchoolAid\Assistant\Support\AssistantTurnSteps}) y de las confirmaciones.
 *
 * Qué argumento o clave del resultado de cada tool es un registro lo dice `AssistantNaming::config('entities')`: por tool, una
 * lista de `{type, id, model_key?, date?}` con rutas `input.<arg>` (los argumentos de la llamada), `output.<clave>` (lo
 * que respondió la tool en el chat, si fue JSON) o `result.<clave>` (lo que devolvió la escritura al confirmarse; un
 * registro por esa ruta es uno que la escritura creó).
 *
 * Una ruta con `*` (`output.data.*.id`) da registro solo si encuentra exactamente un valor: una búsqueda o un listado
 * que halló un solo registro cuenta, uno de varios no llena el panel. De una escritura, `output` es su vista previa:
 * lo que crea se mapea con `result.`.
 */
final class AssistantEntityExtractor
{
    /**
     * Tope de registros de una conversación: el panel los muestra todos, sin paginar.
     */
    public const MAX_ENTITIES = 20;

    private const RESULT_PATH = 'result.';

    private const WILDCARD = '*';

    /**
     * Los registros de las llamadas que terminaron bien (con `tool_result` sin `is_error`; una sin respuesta o con
     * error no cuenta) y de las confirmaciones `confirmed`, en el orden en que aparecieron, sin repetir y hasta
     * {@see self::MAX_ENTITIES}. De una llamada cuentan sus argumentos y lo que respondió (`output`); lo que crea una
     * escritura llega con la confirmación (`result`).
     *
     * @return list<AssistantEntityRef>
     */
    public function fromConversation(AssistantConversation $conversation): array
    {
        $calls = [];
        $answered = [];

        $messages = $conversation->messages()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $responses) => $responses->where('role', 'assistant')->where('kind', AssistantMessageKindEnum::Text->value))
                ->orWhere(fn (Builder $toolResults) => $toolResults->where('role', 'user')->where('kind', AssistantMessageKindEnum::Internal->value)))
            ->orderBy('id')
            ->get(['id', 'role', 'kind', 'content']);

        foreach ($messages as $message) {
            foreach ((array) $message->content as $block) {
                if (! is_array($block)) {
                    continue;
                }

                if ($message->role === 'assistant' && ($block['type'] ?? null) === 'tool_use' && filled($block['id'] ?? null)) {
                    $calls[(string) $block['id']] = [
                        'position' => (int) $message->id,
                        'tool' => (string) ($block['name'] ?? ''),
                        'input' => (array) ($block['input'] ?? []),
                    ];
                }

                if ($message->role === 'user' && ($block['type'] ?? null) === 'tool_result' && ! ($block['is_error'] ?? false)) {
                    $answered[(string) ($block['tool_use_id'] ?? '')] = $this->output($block['content'] ?? null);
                }
            }
        }

        $sources = [];

        foreach ($calls as $toolUseId => $call) {
            if (array_key_exists($toolUseId, $answered)) {
                $sources[] = [
                    'position' => $call['position'],
                    'refs' => $this->refs($call['tool'], ['input' => $call['input'], 'output' => $answered[$toolUseId]]),
                ];
            }
        }

        $confirmations = $conversation->confirmations()
            ->where('status', AssistantConfirmationStatusEnum::Confirmed->value)
            ->orderBy('id')
            ->get(['id', 'message_id', 'tool', 'arguments', 'result', 'status']);

        foreach ($confirmations as $confirmation) {
            $sources[] = ['position' => (int) $confirmation->message_id, 'refs' => $this->fromConfirmation($confirmation)];
        }

        usort($sources, fn (array $first, array $second): int => $first['position'] <=> $second['position']);

        return array_slice($this->unique(array_merge(...array_column($sources, 'refs'))), 0, self::MAX_ENTITIES);
    }

    /**
     * Los registros que tocó o creó una escritura, solo si se confirmó: los de sus argumentos y los de su `result`
     * (estos, `created`).
     *
     * @return list<AssistantEntityRef>
     */
    public function fromConfirmation(AssistantConfirmation $confirmation): array
    {
        if ($confirmation->status !== AssistantConfirmationStatusEnum::Confirmed) {
            return [];
        }

        return $this->unique($this->refs($confirmation->tool, [
            'input' => (array) $confirmation->arguments,
            'result' => (array) $confirmation->result,
        ]));
    }

    /**
     * Un registro por cada entrada de la tool en el mapa cuyo id se encuentre en la llamada (un argumento opcional que
     * no vino, o el resultado de una llamada que todavía no lo tiene, no da registro).
     *
     * @param  array{input: array<string, mixed>, output?: array<mixed>|null, result?: array<string, mixed>}  $call
     * @return list<AssistantEntityRef>
     */
    private function refs(string $tool, array $call): array
    {
        $refs = [];

        foreach ((array) (AssistantNaming::config('entities', [])[$tool] ?? []) as $mapping) {
            $id = $this->id($this->value($call, $mapping['id']));
            $modelKey = isset($mapping['model_key']) ? $this->value($call, $mapping['model_key']) : null;
            $date = isset($mapping['date']) ? $this->value($call, $mapping['date']) : null;

            if ($id === null || (isset($mapping['model_key']) && ! is_string($modelKey))) {
                continue;
            }

            $refs[] = new AssistantEntityRef(
                type: (string) $mapping['type'],
                id: $id,
                modelKey: $modelKey,
                date: is_string($date) ? $date : null,
                created: str_starts_with((string) $mapping['id'], self::RESULT_PATH),
            );
        }

        return $refs;
    }

    /**
     * El valor de la ruta en la llamada; con `*`, el único que encuentre (ninguno o varios: null).
     *
     * @param  array<string, mixed>  $call
     */
    private function value(array $call, string $path): mixed
    {
        if (! str_contains($path, self::WILDCARD)) {
            return data_get($call, $path);
        }

        $found = data_get($call, $path);

        return is_array($found) && count($found) === 1 ? reset($found) : null;
    }

    /**
     * Lo que respondió la tool, si es JSON: texto (como lo guarda el turno) o bloques de texto. Otra cosa, null.
     *
     * @return array<mixed>|null
     */
    private function output(mixed $content): ?array
    {
        if (is_array($content)) {
            $content = implode('', array_map(
                fn (mixed $block): string => is_array($block) && ($block['type'] ?? null) === 'text' ? (string) ($block['text'] ?? '') : '',
                $content,
            ));
        }

        $decoded = is_string($content) ? json_decode($content, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    private function id(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /**
     * Sin repetir, quedándose con la primera aparición.
     *
     * @param  list<AssistantEntityRef>  $refs
     * @return list<AssistantEntityRef>
     */
    private function unique(array $refs): array
    {
        $unique = [];

        foreach ($refs as $ref) {
            $unique[$ref->identity()] ??= $ref;
        }

        return array_values($unique);
    }
}
