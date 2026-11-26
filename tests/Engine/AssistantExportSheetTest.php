<?php

use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\ExcelServiceProvider;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use SchoolAid\Assistant\Services\Exports\AssistantExportSheet;

/*
| The spreadsheet a panel export becomes, rendered for real (no Excel::fake) and read back, so the
| same assertions hold on maatwebsite/excel 3.1 + PhpSpreadsheet 1.x and on 4.x + PhpSpreadsheet 5:
| headings (an amount column labelled with its currency), values, the money number format, the
| document title, and the AfterSheet styling (frozen header row, bold header).
*/

beforeEach(function (): void {
    $this->app->register(ExcelServiceProvider::class);
});

it('renders the export as a real XLSX that reads back the same on every Excel version', function (): void {
    $sheet = new AssistantExportSheet('Pagos de noviembre', [
        ['name' => 'Ana', 'grade' => '5A', 'total' => ['amount' => 1234.5, 'currency' => 'GTQ']],
        ['name' => 'Beto', 'grade' => '6B', 'total' => ['amount' => 20, 'currency' => 'GTQ']],
    ], null);

    $path = tempnam(sys_get_temp_dir(), 'assistant-export').'.xlsx';
    file_put_contents($path, Excel::raw($sheet, ExcelWriter::XLSX));
    $book = IOFactory::load($path);
    @unlink($path);
    $ws = $book->getActiveSheet();

    expect([$ws->getCell('A1')->getValue(), $ws->getCell('B1')->getValue(), $ws->getCell('C1')->getValue()])
        ->toBe(['name', 'grade', 'total (GTQ)'])
        ->and([$ws->getCell('A2')->getValue(), $ws->getCell('B2')->getValue(), (float) $ws->getCell('C2')->getValue()])
        ->toBe(['Ana', '5A', 1234.5])
        ->and([$ws->getCell('A3')->getValue(), (float) $ws->getCell('C3')->getValue()])
        ->toBe(['Beto', 20.0])
        ->and($ws->getStyle('C2')->getNumberFormat()->getFormatCode())->toBe('#,##0.00')
        ->and($book->getProperties()->getTitle())->toBe('Pagos de noviembre')
        ->and($ws->getStyle('A1')->getFont()->getBold())->toBeTrue()
        ->and($ws->getFreezePane())->toBe('A2');
});
