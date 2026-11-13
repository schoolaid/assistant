<?php

namespace SchoolAid\Assistant\Services\Evaluation;

use InvalidArgumentException;
use JsonException;

/**
 * El set de tareas del asistente (`docs/modules/_qa-bank/assistant-task-eval.json`): pedidos como los escribe el staff,
 * con las tools que el agente tiene que llamar, las escrituras que se confirman (en orden) y si debe preguntar o
 * negarse. `ready` = sus tools ya están en el asistente; `pending` = espera tools de la fase 3. Un test valida el set
 * contra el AdminServer y el mapa de grupos.
 *
 * @phpstan-type TaskEvalItem array{id: string, request: string, expectedTools: list<string>, expectedWrites: list<string>, mustAsk: bool, mustRefuse: bool, status: string, notes: string, modules?: list<string>, requires?: list<string>}
 */
final readonly class TaskEvalSet
{
    public const PATH = 'docs/modules/_qa-bank/assistant-task-eval.json';

    public const READY = 'ready';

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<TaskEvalItem>  $items
     */
    private function __construct(public array $meta, public array $items)
    {
    }

    /**
     * @throws InvalidArgumentException el archivo no existe o no es un set válido
     */
    public static function load(?string $path = null): self
    {
        $path ??= base_path(self::PATH);

        if (! is_file($path)) {
            throw new InvalidArgumentException("No existe el set de tareas {$path}.");
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("El set de tareas no es JSON válido: {$exception->getMessage()}");
        }

        if (! is_array($data) || ! is_array($data['items'] ?? null)) {
            throw new InvalidArgumentException('El set de tareas no trae `items`.');
        }

        return new self((array) ($data['meta'] ?? []), array_values($data['items']));
    }

    /**
     * Los ítems `ready`, todos o los de `$only` (en el orden del set).
     *
     * @param  list<string>  $only
     * @return list<TaskEvalItem>
     *
     * @throws InvalidArgumentException un id de `$only` no existe o no está `ready`
     */
    public function ready(array $only = []): array
    {
        $byId = array_column($this->items, null, 'id');

        foreach ($only as $id) {
            if (! isset($byId[$id])) {
                throw new InvalidArgumentException("El set no tiene la tarea «{$id}».");
            }

            if ($byId[$id]['status'] !== self::READY) {
                throw new InvalidArgumentException("La tarea «{$id}» está {$byId[$id]['status']}: sus tools todavía no están en el asistente.");
            }
        }

        return array_values(array_filter(
            $this->items,
            fn (array $item): bool => $item['status'] === self::READY && ($only === [] || in_array($item['id'], $only, true)),
        ));
    }
}
