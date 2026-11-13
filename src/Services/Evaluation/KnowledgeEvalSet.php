<?php

namespace SchoolAid\Assistant\Services\Evaluation;

use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * El set de conocimiento del asistente: las preguntas de `docs/modules/_qa-bank/help-eval.json` (el mismo archivo con
 * el que `assistant:knowledge:eval` mide la búsqueda) corridas de punta a punta con `assistant:eval --set=knowledge`.
 * La búsqueda mide si la página correcta sale entre los resultados; este set mide lo que lee la persona: si el
 * asistente abrió una página que responde ({@see KnowledgeAnswerChecks}) y, con `--judge`, si la respuesta coincide con
 * la guía o dice que la guía no lo cubre ({@see KnowledgeAnswerJudge}).
 *
 * Entran las preguntas con respuesta y, solo con el juez, las negativas que se le hacen a la guía
 * ({@see self::NEGATIVE_TYPES}): sin juez no hay cómo saber si se abstuvo. Las negativas de datos y de acción no
 * entran: las miden el set de tareas y el de salida. Cada ítem se normaliza a la forma de una tarea (sin escrituras,
 * sin preguntar ni negarse) para correr en {@see TaskEvalRunner}; `modules` sale de las páginas etiquetadas, así el
 * reporte de `search-knowledge` califica el módulo que eligió el modelo.
 *
 * @phpstan-type KnowledgeLabel array{page: string, section?: string}
 */
final class KnowledgeEvalSet
{
    public const PATH = 'docs/modules/_qa-bank/help-eval.json';

    public const SPLITS = ['calib', 'test'];

    public const NEGATIVE_TYPES = ['near-miss', 'off-topic'];

    /**
     * Los ítems del `split` (o los de `$only`, sin mirar el split) en forma de tarea. Con `$limit`, una muestra
     * repartida por todo el set (el archivo va ordenado por módulo: los primeros N serían de dos o tres módulos).
     *
     * @param  list<string>  $only
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException el archivo no existe o no es un set válido, el split no existe, o un id de
     *                                  `$only` no existe o es una negativa que este set no corre
     */
    public static function items(string $split, array $only = [], bool $withNegatives = false, ?int $limit = null, ?string $path = null): array
    {
        if (! in_array($split, self::SPLITS, true)) {
            throw new InvalidArgumentException("--split tiene que ser uno de: ".implode(', ', self::SPLITS).'.');
        }

        $items = self::load($path ?? base_path(self::PATH));
        $byId = array_column($items, null, 'id');

        foreach ($only as $id) {
            if (! isset($byId[$id])) {
                throw new InvalidArgumentException("El set de conocimiento no tiene la pregunta «{$id}».");
            }

            if (! self::runs($byId[$id], true)) {
                throw new InvalidArgumentException("La pregunta «{$id}» es una negativa de {$byId[$id]['negativeType']}: la miden los otros sets.");
            }

            if (! self::runs($byId[$id], $withNegatives)) {
                throw new InvalidArgumentException("La pregunta «{$id}» no tiene respuesta en la guía: solo se mide con --judge.");
            }
        }

        $selected = array_values(array_filter($items, fn (array $item): bool => $only !== []
            ? in_array($item['id'], $only, true)
            : ($item['split'] ?? null) === $split && self::runs($item, $withNegatives)));

        return array_map(fn (array $item): array => self::asTask($item), self::sample($selected, $limit));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function asTask(array $item): array
    {
        $labels = array_values((array) ($item['pages'] ?? []));
        $answerable = ($item['negativeType'] ?? null) === null && $labels !== [];
        $modules = array_values(array_unique(array_map(fn (array $label): string => Str::before((string) $label['page'], '/'), $labels)));

        return [
            'id' => (string) $item['id'],
            'request' => (string) $item['question'],
            'expectedTools' => [],
            'expectedWrites' => [],
            'mustAsk' => false,
            'mustRefuse' => false,
            'status' => TaskEvalSet::READY,
            'notes' => (string) ($item['note'] ?? ''),
            ...($modules === [] ? [] : ['modules' => $modules]),
            'knowledge' => ['answerable' => $answerable, 'labels' => $labels],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function runs(array $item, bool $withNegatives): bool
    {
        $negativeType = $item['negativeType'] ?? null;

        return $negativeType === null || ($withNegatives && in_array($negativeType, self::NEGATIVE_TYPES, true));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function sample(array $items, ?int $limit): array
    {
        if ($limit === null || $limit < 1 || count($items) <= $limit) {
            return $items;
        }

        $stride = count($items) / $limit;
        $sample = [];

        for ($index = 0; $index < $limit; $index++) {
            $sample[] = $items[(int) floor($index * $stride)];
        }

        return $sample;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    private static function load(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("No existe el set de conocimiento {$path}.");
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("El set de conocimiento no es JSON válido: {$exception->getMessage()}");
        }

        if (! is_array($data) || ! is_array($data['items'] ?? null)) {
            throw new InvalidArgumentException('El set de conocimiento no trae `items`.');
        }

        return array_values($data['items']);
    }
}
