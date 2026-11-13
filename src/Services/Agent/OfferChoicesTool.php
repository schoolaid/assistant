<?php

namespace SchoolAid\Assistant\Services\Agent;

/**
 * `offer-choices`: el modelo le pide a la persona que elija con botones en vez de listar opciones en texto.
 *
 * Es local del runner ({@see AssistantTurnRunner}): no es tool del `AdminServer`, no está en
 * {@see \SchoolAid\Assistant\Services\Tools\AssistantToolCatalog} y nunca pasa por el puente. Un `offer-choices`
 * válido termina el turno con un mensaje `choices`; la elección llega como el siguiente mensaje de la persona.
 * Elegir una opción solo manda un mensaje, nunca confirma una escritura: las opciones las escribe el modelo.
 *
 * {@see self::validate()} es la validación que vale; el schema de {@see self::definition()} solo guía al modelo.
 */
final class OfferChoicesTool
{
    public const NAME = 'offer-choices';

    public const DESCRIPTION = 'Pide a la persona que elija con botones en vez de listar opciones en texto: varias coincidencias de un cliente, la clase, el plan, la sucursal. Usa como id el id del registro; pon en detail lo que distingue cada opción (código, últimos dígitos del teléfono, sucursal, precio, cupos) y selectable false en las que no se pueden elegir (p. ej. una clase llena). El turno termina ahí: la elección llega como el siguiente mensaje.';

    /**
     * El `tool_result` de un `offer-choices` válido.
     */
    public const OFFERED_RESULT = 'Mostré las opciones; la elección llega en el siguiente mensaje.';

    public const MAX_QUESTION_CHARS = 300;

    public const MIN_OPTIONS = 2;

    public const MAX_OPTIONS = 10;

    public const MAX_ID_CHARS = 64;

    public const MAX_LABEL_CHARS = 120;

    public const MAX_DETAIL_CHARS = 200;

    private const REJECTED_PREFIX = 'No mostré las opciones: ';

    /**
     * La definición para el parámetro `tools` de la Messages API, nunca diferida.
     *
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => self::DESCRIPTION,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'question' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => self::MAX_QUESTION_CHARS,
                        'description' => 'La pregunta, en una frase.',
                    ],
                    'options' => [
                        'type' => 'array',
                        'minItems' => self::MIN_OPTIONS,
                        'maxItems' => self::MAX_OPTIONS,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_ID_CHARS, 'description' => 'El id del registro.'],
                                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_LABEL_CHARS, 'description' => 'El texto del botón.'],
                                'detail' => ['type' => 'string', 'maxLength' => self::MAX_DETAIL_CHARS, 'description' => 'Lo que distingue esta opción de las demás.'],
                                'selectable' => ['type' => 'boolean', 'description' => 'false si no se puede elegir. Por omisión, true.'],
                            ],
                            'required' => ['id', 'label'],
                        ],
                    ],
                ],
                'required' => ['question', 'options'],
            ],
        ];
    }

    /**
     * Las opciones normalizadas (`id` como texto, `detail` null si falta, `selectable` true si falta), o el motivo del
     * rechazo para el modelo. Un `id` entero se acepta como texto: el modelo usa el id del registro.
     *
     * @param  array<string, mixed>  $input
     * @return array{question: string, options: list<array{id: string, label: string, detail: string|null, selectable: bool}>}|string
     */
    public static function validate(array $input): array|string
    {
        $question = $input['question'] ?? null;

        if (! self::isText($question, 1, self::MAX_QUESTION_CHARS)) {
            return self::rejected('question tiene que ser texto de 1 a '.self::MAX_QUESTION_CHARS.' caracteres.');
        }

        $options = $input['options'] ?? null;

        if (! is_array($options) || ! array_is_list($options) || count($options) < self::MIN_OPTIONS || count($options) > self::MAX_OPTIONS) {
            return self::rejected('options tiene que ser una lista de '.self::MIN_OPTIONS.' a '.self::MAX_OPTIONS.' opciones.');
        }

        $normalized = [];

        foreach ($options as $position => $option) {
            $normalizedOption = self::option($option, $position + 1);

            if (is_string($normalizedOption)) {
                return self::rejected($normalizedOption);
            }

            $normalized[] = $normalizedOption;
        }

        $ids = array_column($normalized, 'id');
        $repeated = array_keys(array_filter(array_count_values($ids), fn (int $count): bool => $count > 1));

        if ($repeated !== []) {
            return self::rejected('los id de las opciones se repiten: '.implode(', ', $repeated).'.');
        }

        if (! in_array(true, array_column($normalized, 'selectable'), true)) {
            return self::rejected('ninguna opción se puede elegir (todas tienen selectable false). Si no hay nada que elegir, dilo en texto.', retry: false);
        }

        return ['question' => trim($question), 'options' => $normalized];
    }

    /**
     * @return array{id: string, label: string, detail: string|null, selectable: bool}|string
     */
    private static function option(mixed $option, int $position): array|string
    {
        if (! is_array($option)) {
            return "la opción {$position} tiene que ser un objeto con id y label.";
        }

        $id = is_int($option['id'] ?? null) ? (string) $option['id'] : ($option['id'] ?? null);

        if (! self::isText($id, 1, self::MAX_ID_CHARS)) {
            return "la opción {$position} necesita id: texto de 1 a ".self::MAX_ID_CHARS.' caracteres.';
        }

        if (! self::isText($option['label'] ?? null, 1, self::MAX_LABEL_CHARS)) {
            return "la opción {$position} necesita label: texto de 1 a ".self::MAX_LABEL_CHARS.' caracteres.';
        }

        $detail = $option['detail'] ?? null;

        if ($detail !== null && ! self::isText($detail, 0, self::MAX_DETAIL_CHARS)) {
            return "el detail de la opción {$position} tiene que ser texto de hasta ".self::MAX_DETAIL_CHARS.' caracteres.';
        }

        $selectable = $option['selectable'] ?? true;

        if (! is_bool($selectable)) {
            return "el selectable de la opción {$position} tiene que ser true o false.";
        }

        return [
            'id' => trim($id),
            'label' => trim($option['label']),
            'detail' => $detail === null || trim($detail) === '' ? null : trim($detail),
            'selectable' => $selectable,
        ];
    }

    private static function isText(mixed $value, int $minChars, int $maxChars): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $length = mb_strlen(trim($value));

        return $length >= $minChars && $length <= $maxChars;
    }

    private static function rejected(string $reason, bool $retry = true): string
    {
        return self::REJECTED_PREFIX.$reason.($retry ? ' Corrige y vuelve a llamar '.self::NAME.'.' : '');
    }
}
