<?php

namespace SchoolAid\Assistant\Services\Exports;

use SchoolAid\Assistant\Support\AssistantNaming;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * El xlsx de `export-file`: una hoja con encabezado en negrita y fijo, columnas de monto como número con dos
 * decimales (la moneda va en el encabezado, «Saldo (GTQ)») y el título en las propiedades del libro.
 */
final class AssistantExportSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithProperties
{
    private const MONEY_FORMAT = '#,##0.00';

    /**
     * @param  list<array<string, mixed>>  $rows  filas tal como las devolvió la lectura
     * @param  list<array{field: string, label: string}>|null  $columns  null = todos los campos
     */
    public function __construct(
        private readonly string $title,
        private readonly array $rows,
        private readonly ?array $columns,
    ) {
    }

    /**
     * Encabezados y filas ya aplanados, más qué columnas son montos y de qué moneda.
     *
     * @return array{headings: list<string>, rows: list<list<string|int|float|bool|null>>, money: array<int, string>}
     */
    public function table(): array
    {
        $flat = array_map(fn (array $row): array => AssistantExportRows::flatten($row), $this->rows);
        $currencies = [];

        foreach ($flat as $row) {
            $currencies += $row['currencies'];
        }

        $columns = $this->columns ?? array_map(
            fn (string $field): array => ['field' => $field, 'label' => $field],
            AssistantExportRows::fields($this->rows),
        );

        $headings = [];
        $money = [];

        foreach ($columns as $index => $column) {
            $currency = $currencies[$column['field']] ?? null;
            $headings[] = $currency !== null && ! str_contains($column['label'], $currency) ? "{$column['label']} ({$currency})" : $column['label'];

            if ($currency !== null) {
                $money[$index] = $currency;
            }
        }

        $rows = array_map(
            fn (array $row): array => array_map(fn (array $column): mixed => $row['values'][$column['field']] ?? null, $columns),
            $flat,
        );

        return ['headings' => $headings, 'rows' => $rows, 'money' => $money];
    }

    public function array(): array
    {
        return $this->table()['rows'];
    }

    public function headings(): array
    {
        return $this->table()['headings'];
    }

    public function columnFormats(): array
    {
        $formats = [];

        foreach (array_keys($this->table()['money']) as $index) {
            $formats[Coordinate::stringFromColumnIndex($index + 1)] = self::MONEY_FORMAT;
        }

        return $formats;
    }

    public function properties(): array
    {
        return ['title' => $this->title, 'creator' => AssistantNaming::exportCreator()];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $sheet->freezePane('A2');
                $sheet->getStyle('1:1')->getFont()->setBold(true);
            },
        ];
    }
}
