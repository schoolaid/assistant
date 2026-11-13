<?php

namespace SchoolAid\Assistant\Services\Exports;

use SchoolAid\Assistant\Contracts\Letterhead;
use SchoolAid\Assistant\Models\AssistantExport;
use Dompdf\Dompdf;
use Illuminate\Support\Str;

/**
 * Los PDF del asistente: logo y nombre del market, título, quién lo pidió y cuándo. Hay dos:
 * - **tabla** (`export-file` con `format: "pdf"`): los filtros usados y la tabla, con las mismas columnas y montos que
 *   el xlsx ({@see AssistantExportSheet::table()}). Va horizontal con más de {@see self::PORTRAIT_MAX_COLUMNS}
 *   columnas. Se dibuja en el canvas ({@see AssistantExportTablePdf}), no con la plantilla: con HTML, dompdf no
 *   alcanza la memoria del worker con unos cientos de filas.
 * - **documento** (`export-document`): el markdown del modelo, con la plantilla `print.assistant.export` y el pie
 *   «Redactado por el asistente…».
 *
 * El nombre y el logo los da la app ({@see Letterhead}). dompdf corre sin acceso remoto: el logo va incrustado y el
 * markdown no deja pasar imágenes, enlaces ni HTML.
 */
final class AssistantExportPdf
{
    public const PORTRAIT_MAX_COLUMNS = 6;

    public const DOCUMENT_FOOTER = 'Redactado por el asistente; confirma las cifras en los reportes del panel.';

    public const TABLE_FOOTER = 'Generado por el asistente con los datos del panel.';

    /**
     * Lo que queda del HTML del markdown: estructura de texto y tablas. Sin `a` (queda su texto), `img` ni nada más.
     */
    private const DOCUMENT_TAGS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'br', 'hr', 'ul', 'ol', 'li', 'strong', 'em', 'del', 'code', 'pre',
        'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ];

    public function __construct(private readonly Letterhead $letterhead)
    {
    }

    /**
     * @param  list<array<string, mixed>>  $rows  filas tal como las devolvió la lectura
     */
    public function table(AssistantExport $export, array $rows): string
    {
        $table = (new AssistantExportSheet($export->title, $rows, $export->columns))->table();
        $cells = array_map(
            fn (array $row): array => array_map(
                fn (mixed $value, int $index): string => self::cell($value, isset($table['money'][$index])),
                $row,
                array_keys($row),
            ),
            $table['rows'],
        );
        $filters = self::filters((array) ($export->source['arguments'] ?? []));
        $count = count($cells);
        $meta = [
            implode(' · ', array_filter([$this->requestedBy($export), now()->format('d/m/Y H:i'), $count === 1 ? '1 fila' : "{$count} filas"])),
            ...($filters !== [] ? ['Filtros: '.implode(' · ', $filters)] : []),
        ];

        return (new AssistantExportTablePdf())->render(
            $this->dompdf(),
            count($table['headings']) > self::PORTRAIT_MAX_COLUMNS ? 'landscape' : 'portrait',
            $this->commerce(),
            $export->title,
            $meta,
            $table['headings'],
            $cells,
            $table['money'] + self::numericColumns($table['rows']),
            self::TABLE_FOOTER,
        );
    }

    public function document(AssistantExport $export): string
    {
        return $this->render($export, [
            'documentHtml' => self::documentHtml((string) ($export->source['markdown'] ?? '')),
            'footer' => self::DOCUMENT_FOOTER,
        ], 'portrait');
    }

    /**
     * El markdown del modelo como HTML para dompdf: GFM (tablas incluidas), sin HTML crudo ni enlaces inseguros, y
     * después solo las etiquetas de {@see self::DOCUMENT_TAGS}.
     */
    public static function documentHtml(string $markdown): string
    {
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        return strip_tags($html, self::DOCUMENT_TAGS);
    }

    /**
     * Los argumentos de la lectura como «campo: valor», para el encabezado de la tabla.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<string>
     */
    public static function filters(array $arguments): array
    {
        $filters = [];

        foreach ($arguments as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $text = match (true) {
                is_bool($value) => $value ? 'sí' : 'no',
                is_scalar($value) => (string) $value,
                default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            };

            $filters[] = $key.': '.Str::limit($text, 120);
        }

        return $filters;
    }

    /**
     * Las columnas cuyos valores son todos números (sin contar los vacíos): van a la derecha, como los montos.
     *
     * @param  list<list<mixed>>  $rows
     * @return array<int, true>
     */
    private static function numericColumns(array $rows): array
    {
        $numeric = [];

        foreach (array_keys($rows[0] ?? []) as $index) {
            $values = array_filter(array_column($rows, $index), fn (mixed $value): bool => $value !== null && $value !== '');

            if ($values !== [] && array_filter($values, fn (mixed $value): bool => ! is_int($value) && ! is_float($value)) === []) {
                $numeric[$index] = true;
            }
        }

        return $numeric;
    }

    private static function cell(mixed $value, bool $money): string
    {
        return match (true) {
            $value === null => '',
            $money && is_numeric($value) => number_format((float) $value, 2),
            is_bool($value) => $value ? 'Sí' : 'No',
            default => (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(AssistantExport $export, array $data, string $orientation): string
    {
        return app('dompdf.wrapper')
            ->setOption('isRemoteEnabled', false)
            ->loadView('print.assistant.export', [
                ...$data,
                'title' => $export->title,
                'commerce' => $this->commerce(),
                'requestedBy' => $this->requestedBy($export),
                'generatedAt' => now()->format('d/m/Y H:i'),
            ])
            ->setPaper('letter', $orientation)
            ->output();
    }

    /**
     * Un dompdf con la configuración de la app (fuentes, caché) y sin acceso remoto, para dibujar en su canvas.
     */
    private function dompdf(): Dompdf
    {
        return app('dompdf.wrapper')->setOption('isRemoteEnabled', false)->getDomPDF();
    }

    /**
     * @return array{name: string|null, logo: string|null}
     */
    private function commerce(): array
    {
        return [
            'name' => $this->letterhead->name(),
            'logo' => $this->letterhead->logoDataUri(),
        ];
    }

    private function requestedBy(AssistantExport $export): ?string
    {
        $export->loadMissing('conversation.user');
        $name = $export->conversation?->user?->name;

        return is_string($name) && trim($name) !== '' ? 'Pedido por '.trim($name) : null;
    }
}
