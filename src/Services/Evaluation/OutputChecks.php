<?php

namespace SchoolAid\Assistant\Services\Evaluation;

/**
 * Chequeos de la salida del asistente para el set de salida (`assistant:eval --set=output`), sin llamar la API: miran
 * el texto visible de la respuesta y lo que devolvieron las tools del turno.
 *
 * - **Cifras con respaldo** (aviso, no falla): cada número de la respuesta, y de los datos de una gráfica, con 3 o más
 *   dígitos significativos tiene que aparecer en algún `tool_result` del turno o en el pedido. Los que no aparecen
 *   salen en `ungrounded`: pueden ser una suma legítima o un número inventado, y se revisan a mano. Se ignoran fechas,
 *   horas, años sueltos y porcentajes (también «60.14 %»).
 * - **Montos con moneda** (falla si `expect.money`): hay al menos un monto, y todo número con dos decimales lleva una
 *   moneda al lado o está en una columna de tabla cuyo encabezado nombra la moneda.
 * - **Tabla** (falla si `expect.table`): la respuesta trae una tabla markdown, o una gráfica (`show-chart`), que en el
 *   panel trae su tabla en «Ver datos».
 * - Una respuesta corta que abre diciendo que no hay resultados («No hay eventos próximos.») no necesita tabla ni montos:
 *   es lo correcto cuando la lectura vino vacía.
 * - **Fugas internas** (falla siempre): nombres de tools, `confirmationToken` o JSON crudo.
 * - **Idioma** (falla siempre): la respuesta va en el idioma del pedido, cuando los dos se pueden detectar.
 * - **Gráfica / archivo** (falla si `expect.chart` / `expect.file`): pidió `show-chart` / `export-file` con ese formato
 *   (`export-document` cuenta como `pdf`).
 */
final class OutputChecks
{
    public const CHART_TOOL = 'show-chart';

    public const FILE_TOOL = 'export-file';

    /**
     * El PDF de resumen que redacta el modelo (fase 4): cuenta como archivo `pdf`.
     */
    public const DOCUMENT_TOOL = 'export-document';

    /**
     * Monedas y cómo se escriben junto a un monto.
     */
    private const CURRENCY_PATTERN = '(?:US\$|Q\.?|\$|€|₡|L\.?|S\/\.?|GTQ|USD|MXN|HNL|CRC|EUR|PEN|COP|quetzal(?:es)?|d[oó]lar(?:es)?)';

    private const MIN_SIGNIFICANT_DIGITS = 3;

    private const NO_AMOUNTS = 'no trae montos';

    /**
     * Una respuesta corta, sin tabla, que abre diciendo que no hay resultados: ahí no se exige tabla ni montos.
     */
    private const NO_RESULTS_MAX_CHARS = 600;

    private const NO_RESULTS_PATTERN = '/^\W*(no hay|no tienen|no tiene|no encontr|no se encontr|todav[ií]a no hay|a[uú]n no hay|ning[uú]n[ao]?\b|there (are|is) no|no .{0,40} found|you have no)/iu';

    private const STOPWORDS = [
        'es' => ['de', 'la', 'que', 'el', 'en', 'los', 'las', 'por', 'con', 'para', 'una', 'del', 'se', 'es', 'su', 'al', 'lo', 'como', 'más', 'pero', 'cuántos', 'cuánto', 'hay', 'este', 'mes', 'hoy'],
        'en' => ['the', 'of', 'and', 'to', 'in', 'is', 'for', 'with', 'that', 'on', 'are', 'this', 'how', 'many', 'much', 'what', 'which', 'have', 'from', 'month', 'today'],
    ];

    /**
     * @param  string  $request  el pedido tal como lo escribió la persona
     * @param  string  $answer  el texto visible de las respuestas del turno
     * @param  list<string>  $toolResults  el contenido de los `tool_result` del turno
     * @param  list<array{name: string, input: array<string, mixed>}>  $toolUses  las tools que pidió el modelo, en orden
     * @param  array{table?: bool, money?: bool, chart?: bool, file?: string|null}  $expect
     * @return array{failures: list<string>, ungrounded: list<string>}
     */
    public static function check(string $request, string $answer, array $toolResults, array $toolUses, array $expect): array
    {
        $failures = [];

        if (trim($answer) === '') {
            $failures[] = 'no hubo respuesta visible';
        }

        $empty = self::saysNoResults($answer);
        $charted = in_array(self::CHART_TOOL, array_column($toolUses, 'name'), true);

        if (($expect['table'] ?? false) && ! $empty && ! $charted && ! self::hasTable($answer)) {
            $failures[] = 'no trae tabla';
        }

        if ($expect['money'] ?? false) {
            $failures = [...$failures, ...array_filter(self::moneyFailures($answer), fn (string $failure): bool => ! ($empty && $failure === self::NO_AMOUNTS))];
        }

        $failures = [...$failures, ...self::leaks($answer, array_column($toolUses, 'name'))];

        $requestLanguage = self::language($request);
        $answerLanguage = self::language($answer);

        if ($requestLanguage !== null && $answerLanguage !== null && $requestLanguage !== $answerLanguage) {
            $failures[] = "respondió en «{$answerLanguage}» a un pedido en «{$requestLanguage}»";
        }

        if (($expect['chart'] ?? false) && ! in_array(self::CHART_TOOL, array_column($toolUses, 'name'), true)) {
            $failures[] = 'no mostró gráfica';
        }

        $file = $expect['file'] ?? null;

        if (is_string($file) && ! self::exported($toolUses, $file)) {
            $failures[] = "no generó el archivo {$file}";
        }

        return ['failures' => $failures, 'ungrounded' => self::ungrounded($request, $answer, $toolResults, $toolUses)];
    }

    /**
     * Los números de la respuesta (y de las gráficas y los resúmenes en PDF pedidos) que no aparecen en los resultados de las tools ni en el
     * pedido, tal como se escribieron y sin repetir.
     *
     * @param  list<string>  $toolResults
     * @param  list<array{name: string, input: array<string, mixed>}>  $toolUses
     * @return list<string>
     */
    public static function ungrounded(string $request, string $answer, array $toolResults, array $toolUses): array
    {
        $known = [];

        foreach ([$request, ...$toolResults] as $source) {
            foreach (self::numbers($source, skipNoise: false) as $number) {
                $known[self::canonical($number)] = true;
            }
        }

        $claimed = self::numbers($answer, skipNoise: true);

        foreach ($toolUses as $toolUse) {
            // El resumen en PDF lo redacta el modelo: sus cifras cuentan como las de la respuesta.
            if ($toolUse['name'] === self::DOCUMENT_TOOL && is_string($toolUse['input']['markdown'] ?? null)) {
                array_push($claimed, ...self::numbers($toolUse['input']['markdown'], skipNoise: true));

                continue;
            }

            if ($toolUse['name'] !== self::CHART_TOOL) {
                continue;
            }

            foreach ((array) ($toolUse['input']['series'] ?? []) as $series) {
                foreach ((array) (is_array($series) ? ($series['data'] ?? []) : []) as $value) {
                    if (is_int($value) || is_float($value)) {
                        $claimed[] = (string) $value;
                    }
                }
            }
        }

        $missing = [];

        foreach ($claimed as $number) {
            if (self::significantDigits($number) < self::MIN_SIGNIFICANT_DIGITS) {
                continue;
            }

            $canonical = self::canonical($number);

            if (! isset($known[$canonical]) && ! isset($missing[$canonical])) {
                $missing[$canonical] = $number;
            }
        }

        return array_values($missing);
    }

    /**
     * «No hay eventos próximos.»: la respuesta correcta cuando la lectura vino vacía, sin tabla ni montos.
     */
    public static function saysNoResults(string $answer): bool
    {
        $trimmed = trim($answer);

        if ($trimmed === '' || mb_strlen($trimmed) > self::NO_RESULTS_MAX_CHARS || self::hasTable($trimmed)) {
            return false;
        }

        // Cada párrafo: el texto visible junta lo que el modelo dijo antes de consultar («Reviso…») con la respuesta.
        foreach (preg_split('/\R\s*\R/', $trimmed) ?: [] as $paragraph) {
            if (preg_match(self::NO_RESULTS_PATTERN, trim($paragraph)) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function hasTable(string $text): bool
    {
        return preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/m', $text) === 1;
    }

    /**
     * @return list<string>
     */
    private static function moneyFailures(string $answer): array
    {
        $exemptCells = self::currencyColumnCells($answer);
        $amounts = 0;
        $bare = [];

        foreach (preg_split('/\R/', $answer) ?: [] as $lineIndex => $line) {
            foreach (self::cells($line) as $cellIndex => $cell) {
                $exempt = isset($exemptCells[$lineIndex][$cellIndex]);

                if (preg_match_all('/(?<![\d.,])\d{1,3}(?:,\d{3})*\.\d{2}(?!\d)(?!\s*%)|(?<![\d.,])\d+\.\d{2}(?!\d)(?!\s*%)/u', $cell, $matches, PREG_OFFSET_CAPTURE) === false) {
                    continue;
                }

                foreach ($matches[0] as [$amount, $position]) {
                    $amounts++;
                    $before = mb_substr(substr($cell, 0, $position), -6);
                    $after = substr($cell, $position + strlen($amount), 12);

                    if ($exempt
                        || preg_match('/'.self::CURRENCY_PATTERN.'\s*-?\s*$/iu', $before) === 1
                        || preg_match('/^\s*'.self::CURRENCY_PATTERN.'/iu', $after) === 1) {
                        continue;
                    }

                    $bare[] = $amount;
                }
            }
        }

        if ($amounts === 0) {
            return [self::NO_AMOUNTS];
        }

        return $bare === [] ? [] : ['montos sin moneda: '.implode(', ', array_slice(array_unique($bare), 0, 5))];
    }

    /**
     * Las celdas de las columnas de tabla cuyo encabezado nombra una moneda («Total (Q)», «Monto GTQ»), por línea y
     * columna.
     *
     * @return array<int, array<int, true>>
     */
    private static function currencyColumnCells(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $exempt = [];
        $columns = null;

        foreach ($lines as $index => $line) {
            $isRow = str_starts_with(ltrim($line), '|');

            if (! $isRow) {
                $columns = null;

                continue;
            }

            if ($columns === null) {
                $next = $lines[$index + 1] ?? '';

                if (! self::hasTable($next)) {
                    continue;
                }

                $columns = [];

                foreach (self::cells($line) as $cellIndex => $header) {
                    if (preg_match('/(?<![\p{L}])'.self::CURRENCY_PATTERN.'(?![\p{L}])/iu', $header) === 1) {
                        $columns[$cellIndex] = true;
                    }
                }

                continue;
            }

            foreach (array_keys($columns) as $cellIndex) {
                $exempt[$index][$cellIndex] = true;
            }
        }

        return $exempt;
    }

    /**
     * Las celdas de una fila de tabla; cualquier otra línea es una sola «celda».
     *
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        $trimmed = trim($line);

        if (! str_starts_with($trimmed, '|')) {
            return [$line];
        }

        return array_values(explode('|', trim($trimmed, '|')));
    }

    /**
     * @param  list<string>  $toolNames
     * @return list<string>
     */
    private static function leaks(string $answer, array $toolNames): array
    {
        $failures = [];
        $names = array_filter(array_unique($toolNames), fn (string $name): bool => str_contains($name, '-'));
        $named = array_values(array_filter($names, fn (string $name): bool => preg_match('/(?<![\w-])'.preg_quote($name, '/').'(?![\w-])/u', $answer) === 1));

        if ($named !== []) {
            $failures[] = 'nombra tools: '.implode(', ', $named);
        }

        if (str_contains($answer, 'confirmationToken') || str_contains($answer, 'idempotencyKey')) {
            $failures[] = 'muestra datos de confirmación';
        }

        if (preg_match('/```json|^\s*[\[{]\s*"[\w-]+"\s*:/mi', $answer) === 1) {
            $failures[] = 'muestra JSON crudo';
        }

        return $failures;
    }

    /**
     * `es`, `en` o null si no hay palabras suficientes para decidir.
     */
    public static function language(string $text): ?string
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $scores = array_map(fn (array $stopwords): int => count(array_filter($words, fn (string $word): bool => in_array($word, $stopwords, true))), self::STOPWORDS);
        arsort($scores);
        $best = array_key_first($scores);
        $scoreValues = array_values($scores);

        if ($scoreValues[0] < 2 || $scoreValues[0] < 2 * ($scoreValues[1] ?? 0)) {
            return null;
        }

        return $best;
    }

    /**
     * @param  list<array{name: string, input: array<string, mixed>}>  $toolUses
     */
    private static function exported(array $toolUses, string $format): bool
    {
        foreach ($toolUses as $toolUse) {
            if ($toolUse['name'] === self::FILE_TOOL && ($toolUse['input']['format'] ?? null) === $format) {
                return true;
            }

            if ($toolUse['name'] === self::DOCUMENT_TOOL && $format === 'pdf') {
                return true;
            }
        }

        return false;
    }

    /**
     * Los números de un texto tal como se escribieron. Con `$skipNoise` deja fuera fechas, horas, años sueltos,
     * porcentajes y lo que va en enlaces: lo que la persona no lee como una cifra del negocio.
     *
     * @return list<string>
     */
    private static function numbers(string $text, bool $skipNoise): array
    {
        if ($skipNoise) {
            $text = (string) preg_replace([
                '/https?:\/\/\S+/u',
                '/\b\d{4}-\d{2}-\d{2}(?:[T ][\d:.]+Z?)?\b/u',
                '/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/u',
                '/\b\d{1,2}:\d{2}(?::\d{2})?\b/u',
                '/-?\d+(?:[.,]\d+)?\s*%/u',
            ], ' ', $text);
        }

        preg_match_all('/(?<![\d.])-?\d{1,3}(?:,\d{3})+(?:\.\d+)?|(?<![\d.,])-?\d+(?:\.\d+)?/u', $text, $matches);

        $numbers = $matches[0];

        if ($skipNoise) {
            $numbers = array_filter($numbers, function (string $number): bool {
                $value = ltrim(str_replace(',', '', $number), '-');

                return ! (ctype_digit($value) && (int) $value >= 1900 && (int) $value <= 2100);
            });
        }

        return array_values($numbers);
    }

    /**
     * El número sin separadores de miles ni ceros de sobra: «67,650.00», «67650» y «67650.0» son el mismo.
     */
    private static function canonical(string $number): string
    {
        $value = (float) str_replace(',', '', $number);

        return rtrim(rtrim(number_format(round($value, 2), 2, '.', ''), '0'), '.');
    }

    private static function significantDigits(string $number): int
    {
        $digits = ltrim(str_replace(['-', ',', '.'], '', self::canonical($number)), '0');

        return strlen($digits);
    }
}
