<?php

namespace SchoolAid\Assistant\Services\Agent;

use SchoolAid\Assistant\Support\AssistantNaming;

/**
 * `export-file`: el modelo pide un archivo con los datos de una lectura; nunca escribe las filas.
 *
 * Es local del runner ({@see AssistantTurnRunner}), como {@see ShowChartTool}, y tampoco corta el turno. Aquí solo se
 * valida la forma del pedido. Lo demás lo hace {@see \SchoolAid\Assistant\Services\Exports\AssistantExportRequester}:
 * que la fuente sea una lectura que la persona puede usar, los topes y la primera página. Después
 * {@see \SchoolAid\Assistant\Jobs\BuildAssistantExport} pagina la misma lectura con los permisos de la persona y
 * arma el archivo. Así las cifras salen de la base, no del modelo.
 */
final class ExportFileTool
{
    public const NAME = 'export-file';

    public const DESCRIPTION = 'Genera un archivo para descargar con los datos de una lectura (list-orders, list-accounts-receivable, run-report, list-subscriptions…): pásale la misma tool y los mismos argumentos que usaste (o usarías) para leerlos, sin page ni perPage: el archivo trae todas las páginas. No escribas tú las filas. Pon en columns los campos que importan, con su nombre en el idioma de la persona. format: xlsx (Excel, el de siempre) o pdf (para imprimir o mandar: una tabla con el logo del market, hasta '.self::PDF_MAX_COLUMNS.' columnas, así que en pdf elige las columnas). El archivo aparece en el chat con su botón para descargar; dile en una frase qué contiene.';

    public const FORMATS = ['xlsx', 'pdf'];

    /**
     * Columnas que caben en una página horizontal del PDF sin volverse ilegibles.
     */
    public const PDF_MAX_COLUMNS = 10;

    public const MAX_TITLE_CHARS = 120;

    public const MAX_COLUMNS = 40;

    public const MAX_LABEL_CHARS = 60;

    /**
     * Argumentos que pone el exportador al paginar: el modelo no los manda.
     */
    public const PAGING_ARGUMENTS = ['page', 'perPage'];

    private const REJECTED_PREFIX = 'No generé el archivo: ';

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => AssistantNaming::toolDescription(self::NAME, self::DESCRIPTION),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'format' => ['type' => 'string', 'enum' => self::FORMATS],
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_TITLE_CHARS, 'description' => 'Qué contiene y de cuándo; es el nombre del archivo. P. ej. «Clientes con saldo pendiente — 12 nov 2026».'],
                    'source' => [
                        'type' => 'object',
                        'properties' => [
                            'tool' => ['type' => 'string', 'description' => 'La tool de lectura, p. ej. list-accounts-receivable o run-report.'],
                            'arguments' => ['type' => 'object', 'description' => 'Sus argumentos (filtros, columnas de un reporte…), sin page ni perPage.'],
                        ],
                        'required' => ['tool', 'arguments'],
                    ],
                    'columns' => [
                        'type' => 'array',
                        'maxItems' => self::MAX_COLUMNS,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'field' => ['type' => 'string', 'description' => 'El campo del resultado; los anidados con punto (customer.name).'],
                                'label' => ['type' => 'string', 'description' => 'El encabezado de la columna.'],
                            ],
                            'required' => ['field', 'label'],
                        ],
                        'description' => 'Opcional: las columnas, en orden. Sin ellas van todos los campos.',
                    ],
                ],
                'required' => ['format', 'title', 'source'],
            ],
        ];
    }

    /**
     * El pedido normalizado, o el motivo del rechazo para el modelo.
     *
     * @param  array<string, mixed>  $input
     * @return array{format: string, title: string, source: array{tool: string, arguments: array<string, mixed>}, columns: list<array{field: string, label: string}>|null}|string
     */
    public static function validate(array $input): array|string
    {
        $format = $input['format'] ?? null;

        if (! in_array($format, self::FORMATS, true)) {
            return self::rejected('format tiene que ser uno de: '.implode(', ', self::FORMATS).'.');
        }

        $title = $input['title'] ?? null;

        if (! self::isText($title, self::MAX_TITLE_CHARS)) {
            return self::rejected('title tiene que ser texto de 1 a '.self::MAX_TITLE_CHARS.' caracteres.');
        }

        $source = $input['source'] ?? null;
        $tool = is_array($source) ? ($source['tool'] ?? null) : null;
        $arguments = is_array($source) ? ($source['arguments'] ?? []) : null;

        if (! is_string($tool) || trim($tool) === '' || ! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return self::rejected('source tiene que traer tool (el nombre de una lectura) y arguments (un objeto).');
        }

        if (in_array(trim($tool), [self::NAME, ExportDocumentTool::NAME, ShowChartTool::NAME, OfferChoicesTool::NAME], true)) {
            return self::rejected('source.tool tiene que ser una lectura de datos.');
        }

        $columns = $input['columns'] ?? null;
        $normalizedColumns = null;

        if ($columns !== null && $columns !== []) {
            if (! is_array($columns) || ! array_is_list($columns) || count($columns) > self::MAX_COLUMNS) {
                return self::rejected('columns tiene que ser una lista de hasta '.self::MAX_COLUMNS.' columnas.');
            }

            if ($format === 'pdf' && count($columns) > self::PDF_MAX_COLUMNS) {
                return self::rejected('un pdf lleva hasta '.self::PDF_MAX_COLUMNS.' columnas: elige las que importan, o genera un xlsx con todas.');
            }

            $normalizedColumns = [];

            foreach ($columns as $position => $column) {
                $field = is_array($column) ? ($column['field'] ?? null) : null;
                $label = is_array($column) ? ($column['label'] ?? null) : null;

                if (! self::isText($field, 120) || ! self::isText($label, self::MAX_LABEL_CHARS)) {
                    return self::rejected('la columna '.($position + 1).' necesita field y label (texto de hasta '.self::MAX_LABEL_CHARS.' caracteres).');
                }

                $normalizedColumns[] = ['field' => trim($field), 'label' => trim($label)];
            }
        }

        return [
            'format' => $format,
            'title' => trim($title),
            'source' => ['tool' => trim($tool), 'arguments' => array_diff_key($arguments, array_flip(self::PAGING_ARGUMENTS))],
            'columns' => $normalizedColumns,
        ];
    }

    public static function rejected(string $reason): string
    {
        return self::REJECTED_PREFIX.$reason;
    }

    private static function isText(mixed $value, int $maxChars): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $length = mb_strlen(trim($value));

        return $length >= 1 && $length <= $maxChars;
    }
}
