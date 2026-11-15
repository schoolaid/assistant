<?php

namespace SchoolAid\Assistant\Services\Agent;

use SchoolAid\Assistant\Support\AssistantNaming;

/**
 * `show-chart`: el modelo muestra en el chat una gráfica de números que ya leyó de una tool.
 *
 * Es local del runner ({@see AssistantTurnRunner}), como {@see OfferChoicesTool}: no es tool del `AdminServer` ni pasa
 * por el puente. A diferencia de `offer-choices` **no termina el turno**: el runner guarda un mensaje `chart` (solo del
 * panel, con el spec normalizado en `payload`) y el modelo sigue, normalmente con una frase de conclusión. El panel la
 * dibuja con ApexCharts; nunca hay HTML ni imágenes del modelo.
 *
 * {@see self::validate()} es la validación que vale; el schema de {@see self::definition()} solo guía al modelo.
 */
final class ShowChartTool
{
    public const NAME = 'show-chart';

    public const DESCRIPTION = 'Muestra en el chat una gráfica con números que ya leíste de una tool en esta conversación: comparar categorías (bar), una evolución en el tiempo (line o area) o el reparto de un total (pie o donut). Acompáñala con una frase con la conclusión; no repitas en texto todos sus valores. No la uses para uno o dos valores. Una serie por cada cosa que se compara, con un número por categoría, en el mismo orden.';

    public const SHOWN_RESULT = 'Mostré la gráfica.';

    public const TYPES = ['bar', 'line', 'area', 'pie', 'donut'];

    public const FORMATS = ['money', 'number', 'percent'];

    public const MAX_TITLE_CHARS = 120;

    public const MAX_CATEGORIES = 60;

    public const MAX_CATEGORY_CHARS = 60;

    /**
     * Uno por color de la paleta del panel (`--chart-1` a `--chart-5`): los colores nunca se repiten.
     */
    public const MAX_SERIES = 5;

    public const MAX_SERIES_NAME_CHARS = 60;

    public const MAX_SLICES = 5;

    private const REJECTED_PREFIX = 'No mostré la gráfica: ';

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
                    'type' => ['type' => 'string', 'enum' => self::TYPES],
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_TITLE_CHARS, 'description' => 'Qué muestra y de cuándo, p. ej. «Ventas por sucursal — noviembre 2026».'],
                    'categories' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => self::MAX_CATEGORIES,
                        'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_CATEGORY_CHARS],
                        'description' => 'Las etiquetas del eje (sucursales, días, planes…), en orden.',
                    ],
                    'series' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => self::MAX_SERIES,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_SERIES_NAME_CHARS],
                                'data' => ['type' => 'array', 'items' => ['type' => 'number'], 'description' => 'Un número por categoría, en el mismo orden.'],
                            ],
                            'required' => ['name', 'data'],
                        ],
                        'description' => 'Hasta 5. pie y donut: una sola serie y hasta 5 porciones (agrupa las menores en «Otros»).',
                    ],
                    'format' => [
                        'type' => 'object',
                        'properties' => [
                            'kind' => ['type' => 'string', 'enum' => self::FORMATS],
                            'currency' => ['type' => 'string', 'description' => 'Código ISO de 3 letras (GTQ, USD). Obligatorio con money.'],
                        ],
                        'required' => ['kind'],
                    ],
                    'horizontal' => ['type' => 'boolean', 'description' => 'Solo bar: barras horizontales (etiquetas largas o muchas categorías).'],
                    'stacked' => ['type' => 'boolean', 'description' => 'Solo bar y area: apila las series.'],
                ],
                'required' => ['type', 'title', 'categories', 'series', 'format'],
            ],
        ];
    }

    /**
     * El spec normalizado, o el motivo del rechazo para el modelo.
     *
     * @param  array<string, mixed>  $input
     * @return array{type: string, title: string, categories: list<string>, series: list<array{name: string, data: list<int|float>}>, format: array{kind: string, currency: string|null}, horizontal: bool, stacked: bool}|string
     */
    public static function validate(array $input): array|string
    {
        $type = $input['type'] ?? null;

        if (! in_array($type, self::TYPES, true)) {
            return self::rejected('type tiene que ser uno de: '.implode(', ', self::TYPES).'.');
        }

        $title = $input['title'] ?? null;

        if (! self::isText($title, self::MAX_TITLE_CHARS)) {
            return self::rejected('title tiene que ser texto de 1 a '.self::MAX_TITLE_CHARS.' caracteres.');
        }

        $categories = $input['categories'] ?? null;

        if (! is_array($categories) || ! array_is_list($categories) || $categories === [] || count($categories) > self::MAX_CATEGORIES) {
            return self::rejected('categories tiene que ser una lista de 1 a '.self::MAX_CATEGORIES.' etiquetas.');
        }

        foreach ($categories as $position => $category) {
            if (is_int($category) || is_float($category)) {
                $categories[$position] = $category = (string) $category;
            }

            if (! self::isText($category, self::MAX_CATEGORY_CHARS)) {
                return self::rejected('la categoría '.($position + 1).' tiene que ser texto de 1 a '.self::MAX_CATEGORY_CHARS.' caracteres.');
            }
        }

        $series = $input['series'] ?? null;
        $maxSeries = in_array($type, ['pie', 'donut'], true) ? 1 : self::MAX_SERIES;

        if (! is_array($series) || ! array_is_list($series) || $series === [] || count($series) > $maxSeries) {
            return self::rejected($maxSeries === 1 ? "{$type} lleva una sola serie." : 'series tiene que ser una lista de 1 a '.self::MAX_SERIES.' series.');
        }

        if ($maxSeries === 1 && count($categories) > self::MAX_SLICES) {
            return self::rejected("{$type} admite hasta ".self::MAX_SLICES.' categorías: agrupa las menores en «Otros» o usa bar.');
        }

        $normalizedSeries = [];

        foreach ($series as $position => $entry) {
            $normalized = self::series($entry, $position + 1, count($categories));

            if (is_string($normalized)) {
                return self::rejected($normalized);
            }

            $normalizedSeries[] = $normalized;
        }

        $format = $input['format'] ?? null;
        $kind = is_array($format) ? ($format['kind'] ?? null) : null;

        if (! in_array($kind, self::FORMATS, true)) {
            return self::rejected('format.kind tiene que ser uno de: '.implode(', ', self::FORMATS).'.');
        }

        $currency = is_array($format) && is_string($format['currency'] ?? null) ? strtoupper(trim($format['currency'])) : null;

        if ($kind === 'money' && ($currency === null || preg_match('/^[A-Z]{3}$/', $currency) !== 1)) {
            return self::rejected('con format.kind money, format.currency tiene que ser el código ISO de 3 letras (GTQ, USD).');
        }

        return [
            'type' => $type,
            'title' => trim($title),
            'categories' => array_map(fn (string $category): string => trim($category), $categories),
            'series' => $normalizedSeries,
            'format' => ['kind' => $kind, 'currency' => $kind === 'money' ? $currency : null],
            'horizontal' => $type === 'bar' && ($input['horizontal'] ?? false) === true,
            'stacked' => in_array($type, ['bar', 'area'], true) && ($input['stacked'] ?? false) === true,
        ];
    }

    /**
     * @return array{name: string, data: list<int|float>}|string
     */
    private static function series(mixed $entry, int $position, int $categories): array|string
    {
        if (! is_array($entry)) {
            return "la serie {$position} tiene que ser un objeto con name y data.";
        }

        if (! self::isText($entry['name'] ?? null, self::MAX_SERIES_NAME_CHARS)) {
            return "la serie {$position} necesita name: texto de 1 a ".self::MAX_SERIES_NAME_CHARS.' caracteres.';
        }

        $data = $entry['data'] ?? null;

        if (! is_array($data) || ! array_is_list($data) || count($data) !== $categories) {
            return "la serie {$position} tiene que traer un número por categoría ({$categories}).";
        }

        foreach ($data as $value) {
            if (! (is_int($value) || is_float($value)) || ! is_finite((float) $value)) {
                return "la serie {$position} solo puede traer números.";
            }
        }

        return ['name' => trim($entry['name']), 'data' => $data];
    }

    private static function isText(mixed $value, int $maxChars): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $length = mb_strlen(trim($value));

        return $length >= 1 && $length <= $maxChars;
    }

    private static function rejected(string $reason): string
    {
        return self::REJECTED_PREFIX.$reason.' Corrige y vuelve a llamar '.self::NAME.'.';
    }
}
