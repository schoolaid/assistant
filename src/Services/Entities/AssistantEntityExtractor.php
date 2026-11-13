<?php

namespace SchoolAid\Assistant\Services\Entities;

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
 * Qué argumento o clave del resultado de cada tool es un registro lo dice `config('assistant.entities')`: por tool, una
 * lista de `{type, id, model_key?, date?}` con rutas `input.<arg>` (los argumentos de la llamada) o `result.<clave>`
 * (lo que devolvió la escritura al confirmarse; un registro por esa ruta es uno que la escritura creó).
 */
final class AssistantEntityExtractor
{
    /**
     * Tope de registros de una conversación: el panel los muestra todos, sin paginar.
     */
    public const MAX_ENTITIES = 20;

    private const RESULT_PATH = 'result.';

    /**
     * Los registros de las llamadas que terminaron bien (con `tool_result` sin `is_error`; una sin respuesta o con
     * error no cuenta) y de las confirmaciones `confirmed`, en el orden en que aparecieron, sin repetir y hasta
     * {@see self::MAX_ENTITIES}. De una llamada solo cuentan sus argumentos: lo que devuelve una escritura en el chat es
     * su vista previa, y su resultado llega con la confirmación.
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
                    $answered[(string) ($block['tool_use_id'] ?? '')] = true;
                }
            }
        }

        $sources = [];

        foreach ($calls as $toolUseId => $call) {
            if (isset($answered[$toolUseId])) {
                $sources[] = ['position' => $call['position'], 'refs' => $this->refs($call['tool'], ['input' => $call['input']])];
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
     * @param  array{input: array<string, mixed>, result?: array<string, mixed>}  $call
     * @return list<AssistantEntityRef>
     */
    private function refs(string $tool, array $call): array
    {
        $refs = [];

        foreach ((array) (config('assistant.entities', [])[$tool] ?? []) as $mapping) {
            $id = $this->id(data_get($call, $mapping['id']));
            $modelKey = isset($mapping['model_key']) ? data_get($call, $mapping['model_key']) : null;
            $date = isset($mapping['date']) ? data_get($call, $mapping['date']) : null;

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
