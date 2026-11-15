<?php

namespace SchoolAid\Assistant\Services\Exports;

use SchoolAid\Assistant\Support\AssistantNaming;
use Dompdf\Canvas;
use Dompdf\CanvasFactory;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;

/**
 * La tabla del PDF de `export-file`, dibujada directo en el canvas de dompdf (Cpdf), sin layout HTML.
 *
 * Con la plantilla HTML, dompdf arma un árbol de frames con su estilo por cada celda: 100 filas de 8 columnas pasan
 * de 100 MB, y el worker del asistente corre con 128 MB. Dibujando a mano, la memoria casi no depende de las filas y
 * 1 000 filas salen en segundos. A cambio, el layout es fijo y se resuelve aquí:
 * - el ancho de cada columna sale de su texto (encabezado y celdas), repartido para llenar la página;
 * - el texto se parte en renglones dentro de su columna (hasta {@see self::MAX_LINES}, con «…» si no cabe);
 * - el encabezado de la tabla se repite en cada página, y el pie lleva el texto y el número de página.
 */
final class AssistantExportTablePdf
{
    private const MARGIN = 28.0;

    private const FONT_SIZE = 7.5;

    private const LINE_HEIGHT = 9.5;

    private const PADDING_X = 4.0;

    private const PADDING_Y = 3.0;

    private const MAX_LINES = 3;

    private const MIN_COLUMN_WIDTH = 34.0;

    private const TEXT = [0.13, 0.13, 0.13];

    private const MUTED = [0.42, 0.42, 0.42];

    private const RULE = [0.83, 0.83, 0.83];

    private const HEADER_FILL = [0.945, 0.945, 0.945];

    private Canvas $canvas;

    private FontMetrics $metrics;

    private string $regular;

    private string $bold;

    private float $y = self::MARGIN;

    /** @var list<float> */
    private array $widths = [];

    /**
     * @param  array{name: string|null, logo: string|null}  $commerce  logo como data URI PNG (o null)
     * @param  list<string>  $headings
     * @param  list<list<string>>  $rows  celdas ya formateadas
     * @param  array<int, mixed>  $rightAligned  índices de columna que van a la derecha (montos)
     * @param  list<string>  $meta  renglones bajo el título (quién, cuándo, filtros)
     */
    public function render(
        Dompdf $dompdf,
        string $orientation,
        array $commerce,
        string $title,
        array $meta,
        array $headings,
        array $rows,
        array $rightAligned,
        string $footer,
    ): string {
        $dompdf->setPaper('letter', $orientation);
        $this->canvas = CanvasFactory::get_instance($dompdf, 'letter', $orientation);
        $dompdf->setCanvas($this->canvas);
        $this->metrics = new FontMetrics($this->canvas, $dompdf->getOptions());
        $dompdf->setFontMetrics($this->metrics);
        $this->regular = (string) $this->metrics->getFont('DejaVu Sans', 'normal');
        $this->bold = (string) $this->metrics->getFont('DejaVu Sans', 'bold');
        $this->canvas->add_info('Title', $title);
        $this->canvas->add_info('Creator', AssistantNaming::exportCreator());

        $this->widths = $this->columnWidths($headings, $rows);
        $this->drawHeader($commerce, $title, $meta);
        $this->drawHeadingRow($headings, $rightAligned);

        foreach ($rows as $row) {
            $lines = array_map(fn (string $cell, int $index): array => $this->wrap($cell, $this->widths[$index] - 2 * self::PADDING_X, $this->regular), $row, array_keys($row));
            $height = max(1, ...array_map('count', $lines)) * self::LINE_HEIGHT + 2 * self::PADDING_Y;

            if ($this->y + $height > $this->bottom()) {
                $this->canvas->new_page();
                $this->y = self::MARGIN;
                $this->drawHeadingRow($headings, $rightAligned);
            }

            $this->drawRow($lines, $rightAligned, $this->regular, $height);
        }

        $this->drawFooter($footer);

        return (string) $this->canvas->output();
    }

    private function width(): float
    {
        return (float) $this->canvas->get_width() - 2 * self::MARGIN;
    }

    private function bottom(): float
    {
        return (float) $this->canvas->get_height() - self::MARGIN - 14;
    }

    private function textWidth(string $text, string $font, float $size = self::FONT_SIZE): float
    {
        return (float) $this->metrics->getTextWidth($text, $font, $size);
    }

    /**
     * El ancho natural de cada columna (su texto más largo, sin pasar de un tercio de la página) repartido para
     * llenar la página: si sobra, crece en proporción; si falta, las columnas angostas se quedan con lo suyo y las
     * anchas se reparten el resto (y su texto se parte en renglones).
     *
     * @param  list<string>  $headings
     * @param  list<list<string>>  $rows
     * @return list<float>
     */
    private function columnWidths(array $headings, array $rows): array
    {
        $available = $this->width();
        $cap = $available / 3;
        $natural = [];

        foreach ($headings as $index => $heading) {
            $longest = $this->textWidth($heading, $this->bold);

            foreach (array_slice($rows, 0, 200) as $row) {
                $longest = max($longest, $this->textWidth($row[$index] ?? '', $this->regular));
            }

            $natural[$index] = min($cap, max(self::MIN_COLUMN_WIDTH, $longest + 2 * self::PADDING_X));
        }

        $total = array_sum($natural);

        if ($total <= $available) {
            return array_map(fn (float $width): float => $width * $available / $total, $natural);
        }

        $widths = array_fill(0, count($natural), 0.0);
        $pending = $natural;
        $left = $available;

        while ($pending !== []) {
            $share = $left / count($pending);
            $fits = array_filter($pending, fn (float $width): bool => $width <= $share);

            if ($fits === []) {
                foreach (array_keys($pending) as $index) {
                    $widths[$index] = $share;
                }

                break;
            }

            foreach ($fits as $index => $width) {
                $widths[$index] = $width;
                $left -= $width;
                unset($pending[$index]);
            }
        }

        return $widths;
    }

    /**
     * El texto partido en renglones que caben en `$width`: por palabras, y una palabra más larga que la columna por
     * caracteres. Más de {@see self::MAX_LINES} renglones se cortan con «…».
     *
     * @return list<string>
     */
    private function wrap(string $text, float $width, string $font, float $size = self::FONT_SIZE, int $maxLines = self::MAX_LINES): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '' || $this->textWidth($text, $font, $size) <= $width) {
            return [$text];
        }

        $lines = [];
        $line = '';

        foreach (explode(' ', $text) as $word) {
            $candidate = $line === '' ? $word : "{$line} {$word}";

            if ($this->textWidth($candidate, $font, $size) <= $width) {
                $line = $candidate;

                continue;
            }

            if ($line !== '') {
                $lines[] = $line;
            }

            $line = $word;

            while ($this->textWidth($line, $font, $size) > $width && mb_strlen($line) > 1) {
                $cut = mb_strlen($line) - 1;

                while ($cut > 1 && $this->textWidth(mb_substr($line, 0, $cut), $font, $size) > $width) {
                    $cut--;
                }

                $lines[] = mb_substr($line, 0, $cut);
                $line = mb_substr($line, $cut);
            }
        }

        $lines[] = $line;

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $last = $lines[$maxLines - 1];

            while ($last !== '' && $this->textWidth("{$last}…", $font, $size) > $width) {
                $last = mb_substr($last, 0, -1);
            }

            $lines[$maxLines - 1] = rtrim($last).'…';
        }

        return $lines;
    }

    /**
     * Logo y nombre del market, el título y los renglones de contexto.
     *
     * @param  array{name: string|null, logo: string|null}  $commerce
     * @param  list<string>  $meta
     */
    private function drawHeader(array $commerce, string $title, array $meta): void
    {
        $top = self::MARGIN;
        $logoHeight = 0.0;
        $png = is_string($commerce['logo'] ?? null) && str_contains($commerce['logo'], ',')
            ? base64_decode(explode(',', $commerce['logo'], 2)[1], true)
            : false;
        $size = is_string($png) ? @getimagesizefromstring($png) : false;

        if (is_string($png) && $size !== false && $size[1] > 0) {
            $logoHeight = 36.0;
            $logoWidth = min(160.0, $logoHeight * $size[0] / $size[1]);
            $logoHeight = $logoWidth * $size[1] / $size[0];
            $this->canvas->get_cpdf()->addPngFromBuf($png, 'assistant-logo', self::MARGIN, $this->canvas->get_height() - $top - $logoHeight, $logoWidth, $logoHeight);
        }

        $name = trim((string) ($commerce['name'] ?? ''));

        if ($name !== '') {
            $nameWidth = $this->textWidth($name, $this->bold, 11);
            $this->canvas->text(self::MARGIN + $this->width() - $nameWidth, $top + max(0, ($logoHeight - 13) / 2), $name, $this->bold, 11, self::TEXT);
        }

        $this->y = $top + max($logoHeight, 14) + 10;

        foreach ($this->wrap($title, $this->width(), $this->bold, 12, 2) as $line) {
            $this->canvas->text(self::MARGIN, $this->y, $line, $this->bold, 12, self::TEXT);
            $this->y += 15;
        }

        $this->y += 1;

        foreach ($meta as $text) {
            foreach ($this->wrap($text, $this->width(), $this->regular, 8, 4) as $line) {
                $this->canvas->text(self::MARGIN, $this->y, $line, $this->regular, 8, self::MUTED);
                $this->y += 10.5;
            }
        }

        $this->y += 8;
    }

    /**
     * @param  list<string>  $headings
     * @param  array<int, mixed>  $rightAligned
     */
    private function drawHeadingRow(array $headings, array $rightAligned): void
    {
        $lines = array_map(fn (string $heading, int $index): array => $this->wrap($heading, $this->widths[$index] - 2 * self::PADDING_X, $this->bold, self::FONT_SIZE, 2), $headings, array_keys($headings));
        $height = max(1, ...array_map('count', $lines)) * self::LINE_HEIGHT + 2 * self::PADDING_Y;

        $this->canvas->filled_rectangle(self::MARGIN, $this->y, $this->width(), $height, self::HEADER_FILL);
        $this->drawRow($lines, $rightAligned, $this->bold, $height);
    }

    /**
     * Una fila ya partida en renglones, con su línea de abajo.
     *
     * @param  list<list<string>>  $lines
     * @param  array<int, mixed>  $rightAligned
     */
    private function drawRow(array $lines, array $rightAligned, string $font, float $height): void
    {
        $x = self::MARGIN;

        foreach ($lines as $index => $cellLines) {
            $width = $this->widths[$index];

            foreach ($cellLines as $number => $line) {
                $lineX = isset($rightAligned[$index])
                    ? $x + $width - self::PADDING_X - $this->textWidth($line, $font)
                    : $x + self::PADDING_X;
                $this->canvas->text($lineX, $this->y + self::PADDING_Y + $number * self::LINE_HEIGHT, $line, $font, self::FONT_SIZE, self::TEXT);
            }

            $x += $width;
        }

        $this->y += $height;
        $this->canvas->line(self::MARGIN, $this->y, self::MARGIN + $this->width(), $this->y, self::RULE, 0.5);
    }

    private function drawFooter(string $footer): void
    {
        $y = (float) $this->canvas->get_height() - self::MARGIN;
        $this->canvas->page_text(self::MARGIN, $y, $footer, $this->regular, 7, self::MUTED);
        $label = 'Página {PAGE_NUM} de {PAGE_COUNT}';
        $this->canvas->page_text(self::MARGIN + $this->width() - $this->textWidth('Página 00 de 00', $this->regular, 7), $y, $label, $this->regular, 7, self::MUTED);
    }
}
