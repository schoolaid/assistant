<?php

namespace SchoolAid\Assistant\Services\Entities;

use SchoolAid\Assistant\Contracts\EntityLabels;
use SchoolAid\Assistant\Support\AssistantNaming;
use Closure;

/**
 * Los registros que la persona adjunta a su mensaje (`mentions`): sus reglas de validación, cómo se guardan en
 * `messages.payload` (`{"mentions": [{type, id, model_key}]}`, solo los refs) y el bloque con el que los lee el modelo.
 *
 * El motor no lista los tipos válidos: un adjunto vale si {@see EntityLabels} lo resuelve con los scopes y permisos
 * de la petición. `label` y `detail` salen siempre de ahí, nunca de la petición.
 */
final class AssistantMentions
{
    /**
     * Tope de adjuntos por mensaje.
     */
    public const MAX = 10;

    /**
     * La primera línea del bloque que lee el modelo; se explica sola porque las conversaciones viejas no tienen ninguna
     * instrucción sobre adjuntos.
     */
    public const BLOCK_HEADER = '[La persona adjuntó estos registros a su mensaje. Sus ids son exactos: úsalos tal cual, sin buscarlos.';

    public function __construct(private readonly EntityLabels $labels)
    {
    }

    /**
     * Las reglas de una lista de refs bajo `$field` (`mentions` en un mensaje, `refs` al etiquetar). Sin repetidos por
     * `type` + `model_key` + `id` (la familia 5 y el estudiante 5 son dos registros), con la llave del error en la
     * lista misma. `type` y `model_key` solo con letras, números, `-` y `_`: van tal cual en el bloque del modelo.
     * Con `$required`, la lista tiene que venir y traer al menos un ref (al etiquetar).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $field, bool $required = false): array
    {
        return [
            $field => [$required ? 'required' : 'nullable', 'list', 'max:'.self::MAX, self::distinctRule()],
            "{$field}.*" => ['array'],
            "{$field}.*.type" => ['required', 'string', 'alpha_dash:ascii', 'max:64'],
            "{$field}.*.id" => ['required', 'integer', 'min:1'],
            "{$field}.*.model_key" => ['nullable', 'string', 'alpha_dash:ascii', 'max:64'],
        ];
    }

    /**
     * Los textos de error de {@see self::rules()} bajo `$field`: el mismo para cada regla de un adjunto.
     *
     * @return array<string, string>
     */
    public static function messages(string $field): array
    {
        $invalid = AssistantNaming::trans('validation.mentions_invalid');

        return [
            "{$field}.required" => AssistantNaming::trans('validation.mentions_required'),
            "{$field}.list" => AssistantNaming::trans('validation.mentions_list'),
            "{$field}.max" => AssistantNaming::trans('validation.mentions_max', ['max' => self::MAX]),
            "{$field}.*.array" => $invalid,
            "{$field}.*.type.required" => $invalid,
            "{$field}.*.type.string" => $invalid,
            "{$field}.*.type.alpha_dash" => $invalid,
            "{$field}.*.type.max" => $invalid,
            "{$field}.*.id.required" => $invalid,
            "{$field}.*.id.integer" => $invalid,
            "{$field}.*.id.min" => $invalid,
            "{$field}.*.model_key.string" => $invalid,
            "{$field}.*.model_key.alpha_dash" => $invalid,
            "{$field}.*.model_key.max" => $invalid,
        ];
    }

    /**
     * De `[{type, id, model_key?}]` ya validado. El `id` se convierte a entero (por query string llega como texto).
     *
     * @param  array<array-key, mixed>  $items
     * @return list<AssistantEntityRef>
     */
    public static function refs(array $items): array
    {
        $refs = [];

        foreach ($items as $item) {
            $modelKey = $item['model_key'] ?? null;

            $refs[] = new AssistantEntityRef(
                (string) $item['type'],
                (int) $item['id'],
                filled($modelKey) ? (string) $modelKey : null,
            );
        }

        return $refs;
    }

    /**
     * Lo que se guarda en `messages.payload.mentions`.
     *
     * @param  list<AssistantEntityRef>  $refs
     * @return list<array{type: string, id: int, model_key: string|null}>
     */
    public static function toPayload(array $refs): array
    {
        return array_map(
            fn (AssistantEntityRef $ref): array => ['type' => $ref->type, 'id' => $ref->id, 'model_key' => $ref->modelKey],
            array_values($refs),
        );
    }

    /**
     * Los adjuntos de un `messages.payload`; de cualquier otra cosa (un `chart`, un `file`, null), ninguno. Una entrada
     * que no tiene la forma de {@see self::toPayload()} se salta: una fila dañada no tumba el historial.
     *
     * @return list<AssistantEntityRef>
     */
    public static function fromPayload(mixed $payload): array
    {
        if (! is_array($payload) || ! is_array($payload['mentions'] ?? null)) {
            return [];
        }

        $items = array_filter(
            $payload['mentions'],
            fn (mixed $item): bool => is_array($item)
                && is_string($item['type'] ?? null)
                && is_numeric($item['id'] ?? null)
                && (int) $item['id'] > 0
                && (is_string($item['model_key'] ?? null) || ($item['model_key'] ?? null) === null),
        );

        return self::refs($items);
    }

    /**
     * Alineada con `$refs`: la entidad etiquetada de cada uno (la forma de {@see EntityLabels::label()}) o null si no
     * se puede resolver. Una sola llamada a `labelEach`, con una lista por ref.
     *
     * Un ref con `model_key` se etiqueta también sin él, en la misma llamada: si así resuelve, la llave no significa
     * nada para ese tipo (solo la usan los tipos que la necesitan para elegir el modelo) y es texto de la petición que
     * nadie avala y que llegaría al bloque del modelo, así que el ref cuenta como no resoluble (null). El motor no
     * sabe qué tipos usan la llave: se lo pregunta al {@see EntityLabels}.
     *
     * @param  list<AssistantEntityRef>  $refs
     * @return list<array{type: string, resource: string, id: int, date: string|null, label: string, detail: string|null, created: bool}|null>
     */
    public function labelled(array $refs): array
    {
        $refs = array_values($refs);

        if ($refs === []) {
            return [];
        }

        $lists = [];

        foreach ($refs as $index => $ref) {
            $lists["ref{$index}"] = [$ref];

            if ($ref->modelKey !== null) {
                $lists["bare{$index}"] = [new AssistantEntityRef($ref->type, $ref->id, null, $ref->date, $ref->created)];
            }
        }

        $labelled = $this->labels->labelEach($lists);

        return array_map(
            fn (int $index): ?array => isset($labelled["bare{$index}"][0]) ? null : ($labelled["ref{$index}"][0] ?? null),
            array_keys($refs),
        );
    }

    /**
     * El bloque que lee el modelo: {@see self::BLOCK_HEADER} y una línea por adjunto etiquetado, con `]` al final de la
     * última. Todo lo que va en una línea (tipo, `modelKey`, `label` y `detail`) se aplana para que no pueda cerrar el
     * bloque ni abrir otra línea, también en refs que no pasaron por {@see self::rules()} ({@see self::fromPayload()}).
     *
     * @param  list<AssistantEntityRef>  $refs
     * @param  list<array{label: string, detail: string|null}|null>  $labelled  alineada con `$refs` ({@see self::labelled()})
     */
    public static function block(array $refs, array $labelled): string
    {
        $lines = [self::BLOCK_HEADER];

        foreach (array_values($refs) as $index => $ref) {
            $entity = $labelled[$index] ?? null;

            if ($entity === null) {
                continue;
            }

            $modelKey = $ref->modelKey === null ? '' : 'modelKey '.self::flatten($ref->modelKey).', ';
            $detail = self::flatten($entity['detail'] ?? null);

            $lines[] = '- '.self::flatten($ref->type)." ({$modelKey}id {$ref->id}): ".self::flatten($entity['label'])
                .($detail === '' ? '' : " — {$detail}");
        }

        return implode("\n", $lines).']';
    }

    /**
     * Sin saltos de línea, tabuladores ni corchetes, y con un solo espacio entre palabras.
     */
    private static function flatten(?string $text): string
    {
        $text = preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', (string) $text) ?? '';

        return trim(preg_replace('/\s{2,}/u', ' ', str_replace(['[', ']'], '', $text)) ?? '');
    }

    /**
     * Rechaza la lista si dos entradas son el mismo registro (`type` + `model_key` + `id`).
     */
    private static function distinctRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $seen = [];

            foreach ($value as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = $item['id'] ?? null;
                $identity = json_encode([
                    $item['type'] ?? null,
                    $item['model_key'] ?? null,
                    is_numeric($id) ? (int) $id : $id,
                ]);

                if (isset($seen[$identity])) {
                    $fail(AssistantNaming::trans('validation.mentions_distinct'));

                    return;
                }

                $seen[$identity] = true;
            }
        };
    }
}
