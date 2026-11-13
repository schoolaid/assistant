<?php

namespace SchoolAid\Assistant\Services\Settings;

use SchoolAid\Assistant\Enums\AssistantLimitEnum;

/**
 * Un límite del asistente ya resuelto para un market ({@see AssistantSettings::limits()}). `effective` es lo que de
 * verdad se aplica (null = sin límite) y `source` por qué vale eso:
 *
 * - `lock`: lo bloqueó central.
 * - `own_key`: límite de dinero con llave propia y sin valor del market: sin tope.
 * - `market`: lo que eligió el market.
 * - `default`: el valor por omisión de central (o el del código).
 * - `max`: el techo de central, porque el valor del market o el por omisión lo pasaba o no había ninguno.
 * - `none`: no hay valor ni techo: sin límite.
 *
 * `default` y `max` son los que aplican a este market: con llave propia, los de dinero salen null.
 */
final readonly class AssistantLimitState
{
    public const SOURCE_LOCK = 'lock';

    public const SOURCE_OWN_KEY = 'own_key';

    public const SOURCE_MARKET = 'market';

    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_MAX = 'max';

    public const SOURCE_NONE = 'none';

    public function __construct(
        public AssistantLimitEnum $limit,
        public ?float $value,
        public ?float $default,
        public ?float $max,
        public bool $locked,
        public ?float $effective,
        public string $source,
    ) {
    }

    /**
     * @return array{value: float|int|null, default: float|int|null, max: float|int|null, locked: bool, effective: float|int|null, source: string}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->format($this->value),
            'default' => $this->format($this->default),
            'max' => $this->format($this->max),
            'locked' => $this->locked,
            'effective' => $this->format($this->effective),
            'source' => $this->source,
        ];
    }

    private function format(?float $number): float|int|null
    {
        return self::formatFor($this->limit, $number);
    }

    /**
     * Enteros para los límites enteros (mensajes, porcentaje); hasta 4 decimales para los de dinero.
     */
    public static function formatFor(AssistantLimitEnum $limit, ?float $number): float|int|null
    {
        if ($number === null) {
            return null;
        }

        return $limit->isInteger() ? (int) round($number) : round($number, 4);
    }
}
