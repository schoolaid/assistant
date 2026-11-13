<?php

namespace SchoolAid\Assistant\Services\Evaluation;

use InvalidArgumentException;

/**
 * El set de salida del asistente (`docs/modules/_qa-bank/assistant-output-eval.json`): preguntas de lectura como las
 * escribe el staff, con lo que la respuesta tiene que traer (`expect`: tabla, montos con moneda, gráfica, archivo). Lo
 * corre `assistant:eval --set=output` con los chequeos de {@see OutputChecks}, sin confirmar nada: una vista previa en
 * una pregunta de lectura es una falla.
 *
 * Tiene la misma forma de archivo y los mismos `status`, placeholders y `requires` que {@see TaskEvalSet}; cada ítem se
 * normaliza a la forma de una tarea (sin escrituras, sin preguntar ni negarse) para correr en {@see TaskEvalRunner}.
 *
 * @phpstan-type OutputExpect array{table: bool, money: bool, chart: bool, file: string|null}
 */
final class OutputEvalSet
{
    public const PATH = 'docs/modules/_qa-bank/assistant-output-eval.json';

    public const FILE_FORMATS = ['xlsx', 'pdf'];

    /**
     * Los ítems `ready` (todos o los de `$only`) en forma de tarea, con su `expect` normalizado.
     *
     * @param  list<string>  $only
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException el archivo no existe, no es un set válido o un id de `$only` no está `ready`
     */
    public static function ready(array $only = [], ?string $path = null): array
    {
        $items = TaskEvalSet::load($path ?? base_path(self::PATH))->ready($only);

        return array_map(fn (array $item): array => self::asTask($item), $items);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function asTask(array $item): array
    {
        $expect = (array) ($item['expect'] ?? []);
        $file = $expect['file'] ?? null;

        if ($file !== null && ! in_array($file, self::FILE_FORMATS, true)) {
            throw new InvalidArgumentException("El ítem «{$item['id']}» espera un archivo «{$file}»: tiene que ser xlsx, pdf o null.");
        }

        return [
            ...$item,
            'expectedTools' => array_values((array) ($item['expectedTools'] ?? [])),
            'expectedWrites' => [],
            'mustAsk' => false,
            'mustRefuse' => false,
            'expect' => [
                'table' => (bool) ($expect['table'] ?? false),
                'money' => (bool) ($expect['money'] ?? false),
                'chart' => (bool) ($expect['chart'] ?? false),
                'file' => $file,
            ],
        ];
    }
}
