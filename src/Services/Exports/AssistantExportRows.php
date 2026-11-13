<?php

namespace SchoolAid\Assistant\Services\Exports;

use SchoolAid\Assistant\Services\Tools\ToolBridge;
use SchoolAid\Assistant\Services\Tools\ToolCallResult;
use RuntimeException;

/**
 * Las filas de una lectura, para `export-file`. Solo se exportan dos formas de respuesta:
 * - **`PaginatedJson`**: `{data: [...], pagination: {total, perPage, page, lastPage}}`. Es la de las lecturas paginadas
 *   y la de `run-report` paginado.
 * - **`run-report` sin paginar**: `{data: [...], _truncated}`, con un tope de 50 filas. Con `_truncated` no se exporta:
 *   hay que acotar con filtros.
 *
 * Cada fila se aplana a columnas: lo anidado con punto (`customer.name`), un monto `{amount, currency}` como número con
 * su moneda en la columna, y una lista de valores como texto separado por comas.
 */
final class AssistantExportRows
{
    /**
     * La página leída, o null si la respuesta no tiene una forma exportable.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, lastPage: int}|null
     */
    public static function page(ToolCallResult $result): ?array
    {
        $payload = $result->payload;

        if ($result->isError || ! is_array($payload) || ! is_array($payload['data'] ?? null) || ! array_is_list($payload['data'])) {
            return null;
        }

        $rows = array_values(array_filter($payload['data'], 'is_array'));
        $pagination = $payload['pagination'] ?? null;

        if (is_array($pagination) && isset($pagination['total'], $pagination['lastPage'])) {
            return ['rows' => $rows, 'total' => (int) $pagination['total'], 'lastPage' => max(1, (int) $pagination['lastPage'])];
        }

        if (array_key_exists('_truncated', $payload)) {
            return $payload['_truncated'] === true ? null : ['rows' => $rows, 'total' => count($rows), 'lastPage' => 1];
        }

        return null;
    }

    /**
     * Todas las filas de la lectura, página por página por el puente (con los permisos y la sucursal de quien la pidió,
     * que pone el contexto del turno), hasta `$maxRows`.
     *
     * @param  array{tool: string, arguments: array<string, mixed>}  $source
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException una página falló o cambió de forma
     */
    public static function collect(ToolBridge $bridge, array $source, string $runId, int $pageSize, int $maxRows): array
    {
        $rows = [];
        $page = 1;

        do {
            $result = $bridge->call($source['tool'], [...$source['arguments'], 'page' => $page, 'perPage' => $pageSize], $runId, fromModel: false);
            $read = self::page($result);

            if ($read === null) {
                throw new RuntimeException("La página {$page} de {$source['tool']} no se pudo leer: ".mb_substr($result->text, 0, 300));
            }

            array_push($rows, ...$read['rows']);
            $page++;
        } while ($page <= $read['lastPage'] && count($rows) < $maxRows && $read['rows'] !== []);

        return array_slice($rows, 0, $maxRows);
    }

    /**
     * Una fila aplanada: `values` por columna y `currencies` de las columnas de monto.
     *
     * @param  array<string, mixed>  $row
     * @return array{values: array<string, string|int|float|bool|null>, currencies: array<string, string>}
     */
    public static function flatten(array $row, string $prefix = ''): array
    {
        $values = [];
        $currencies = [];

        foreach ($row as $key => $value) {
            $column = $prefix.$key;

            if (is_array($value) && self::isMoney($value)) {
                $values[$column] = is_numeric($value['amount']) ? (float) $value['amount'] : null;
                $currencies[$column] = (string) $value['currency'];

                continue;
            }

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $nested = self::flatten($value, $column.'.');
                $values += $nested['values'];
                $currencies += $nested['currencies'];

                continue;
            }

            $values[$column] = match (true) {
                is_array($value) => implode(', ', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : (string) json_encode($item, JSON_UNESCAPED_UNICODE), $value)),
                is_scalar($value), $value === null => $value,
                default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
            };
        }

        return ['values' => $values, 'currencies' => $currencies];
    }

    /**
     * Los campos de una página, aplanados y en el orden en que aparecen: para validar `columns` antes de encolar.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    public static function fields(array $rows): array
    {
        $fields = [];

        foreach ($rows as $row) {
            foreach (array_keys(self::flatten($row)['values']) as $field) {
                $fields[$field] = true;
            }
        }

        return array_keys($fields);
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function isMoney(array $value): bool
    {
        return array_key_exists('amount', $value) && is_string($value['currency'] ?? null) && count($value) <= 3;
    }
}
