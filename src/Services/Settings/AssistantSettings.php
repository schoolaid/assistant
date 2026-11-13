<?php

namespace SchoolAid\Assistant\Services\Settings;

use SchoolAid\Assistant\Contracts\AccessGate;
use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Enums\AssistantApiKeyStatusEnum;
use SchoolAid\Assistant\Enums\AssistantKeySourceEnum;
use SchoolAid\Assistant\Enums\AssistantLimitEnum;
use SchoolAid\Assistant\Models\AssistantApiKey;
use SchoolAid\Assistant\Models\AssistantLimit;
use SchoolAid\Assistant\Models\AssistantTenantSetting;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Llave y límites del asistente del market en curso (siempre dentro de la tenancy: el techo del tope mensual es un
 * parámetro del tenant). Es la única regla detrás del valor efectivo de cada límite: la usan el presupuesto, el
 * limitador `assistant`, el panel del market y central. El front no lo recalcula.
 *
 * Tres niveles por límite ({@see AssistantLimit}): lo general de central (`default`, `max`), lo de central para este
 * market (`max`, bloqueo) y lo del market (`value`). El valor efectivo:
 *
 * 1. Bloqueado por central → el valor del bloqueo.
 * 2. Límite de dinero con llave propia → lo que eligió el market (sin valor, sin tope): el costo es suyo.
 * 3. Si no, el menor entre lo del market (o el valor por omisión) y el techo. El techo de este market reemplaza al
 *    general; el del tope mensual es el parámetro `assistant_monthly_budget_usd` (lo que SAID fija por market desde
 *    antes de esta configuración), así que el tope mensual no tiene techo general.
 *
 * Llave: la propia si central le permite traerla y el market la cargó (aunque Anthropic la haya rechazado: una llave
 * propia que falla no se cambia por la de SAID, que pagaría sin tope); si no, la de SAID.
 */
class AssistantSettings
{
    public function __construct(
        private readonly AccessGate $access,
        private readonly Workspace $workspace,
    ) {
    }

    public function keySource(): AssistantKeySourceEnum
    {
        return $this->ownKeyAllowed() && $this->ownKey() !== null
            ? AssistantKeySourceEnum::Own
            : AssistantKeySourceEnum::Said;
    }

    /**
     * El market usa su llave y Anthropic ya la rechazó: no hay con qué llamar hasta que la cambie o la quite.
     */
    public function ownKeyRejected(): bool
    {
        return $this->keySource() === AssistantKeySourceEnum::Own
            && $this->ownKey()?->status === AssistantApiKeyStatusEnum::Invalid;
    }

    public function ownKeyAllowed(): bool
    {
        return (bool) AssistantTenantSetting::query()->where('tenant_id', $this->tenantId())->value('own_key_allowed');
    }

    public function ownKey(): ?AssistantApiKey
    {
        return AssistantApiKey::query()->where('tenant_id', $this->tenantId())->first();
    }

    public function generalKey(): ?AssistantApiKey
    {
        return AssistantApiKey::query()->whereNull('tenant_id')->first();
    }

    public function limit(AssistantLimitEnum $limit): AssistantLimitState
    {
        return $this->limits()[$limit->value];
    }

    public function effective(AssistantLimitEnum $limit): ?float
    {
        return $this->limit($limit)->effective;
    }

    /**
     * @return array<string, AssistantLimitState> por clave de límite, en el orden del enum
     */
    public function limits(): array
    {
        $rows = AssistantLimit::query()
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $this->tenantId()))
            ->get();
        $ownKey = $this->keySource() === AssistantKeySourceEnum::Own;
        $states = [];

        foreach (AssistantLimitEnum::cases() as $limit) {
            $states[$limit->value] = $this->resolve(
                $limit,
                $this->row($rows, null, $limit),
                $this->row($rows, $this->tenantId(), $limit),
                $ownKey,
            );
        }

        return $states;
    }

    /**
     * Lo general de central: valor por omisión y techo de cada límite.
     *
     * @return array<string, array{default: float|int|null, max: float|int|null}>
     */
    public function generalLimits(): array
    {
        $rows = AssistantLimit::query()->whereNull('tenant_id')->get();
        $limits = [];

        foreach (AssistantLimitEnum::cases() as $limit) {
            $row = $this->row($rows, null, $limit);
            $limits[$limit->value] = [
                'default' => AssistantLimitState::formatFor($limit, self::number($row?->value) ?? $limit->builtInDefault()),
                'max' => AssistantLimitState::formatFor($limit, self::hasGeneralCeiling($limit) ? self::number($row?->max) : null),
            ];
        }

        return $limits;
    }

    /**
     * Lo que central fijó para este market: techo (el del tope mensual es el parámetro) y bloqueo de cada límite.
     *
     * @return array<string, array{max: float|int|null, lock: array{value: float|int|null}|null}>
     */
    public function overrides(): array
    {
        $rows = AssistantLimit::query()->where('tenant_id', $this->tenantId())->get();
        $overrides = [];

        foreach (AssistantLimitEnum::cases() as $limit) {
            $row = $this->row($rows, $this->tenantId(), $limit);
            $overrides[$limit->value] = [
                'max' => AssistantLimitState::formatFor($limit, $limit->hasCeiling() ? $this->marketCeiling($limit, $row) : null),
                'lock' => $row?->locked ? ['value' => AssistantLimitState::formatFor($limit, self::number($row->locked_value))] : null,
            ];
        }

        return $overrides;
    }

    /**
     * El tope mensual no tiene techo general: el de cada market (el parámetro) existe siempre y lo reemplazaría.
     */
    public static function hasGeneralCeiling(AssistantLimitEnum $limit): bool
    {
        return $limit->hasCeiling() && $limit !== AssistantLimitEnum::MonthlyBudgetUsd;
    }

    public static function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private function resolve(AssistantLimitEnum $limit, ?AssistantLimit $general, ?AssistantLimit $market, bool $ownKey): AssistantLimitState
    {
        $value = self::number($market?->value);
        $locked = (bool) $market?->locked;
        $ignoresSaid = $limit->isMoney() && $ownKey;
        $default = $ignoresSaid ? null : (self::number($general?->value) ?? $limit->builtInDefault());
        $max = $ignoresSaid || ! $limit->hasCeiling()
            ? null
            : ($this->marketCeiling($limit, $market) ?? (self::hasGeneralCeiling($limit) ? self::number($general?->max) : null));

        if ($locked) {
            return new AssistantLimitState($limit, $value, $default, $max, true, self::number($market->locked_value), AssistantLimitState::SOURCE_LOCK);
        }

        if ($ignoresSaid) {
            return new AssistantLimitState($limit, $value, null, null, false, $value, $value === null ? AssistantLimitState::SOURCE_OWN_KEY : AssistantLimitState::SOURCE_MARKET);
        }

        $candidate = $value ?? $default;
        $source = $value !== null ? AssistantLimitState::SOURCE_MARKET : AssistantLimitState::SOURCE_DEFAULT;

        if ($candidate === null) {
            [$candidate, $source] = $max === null ? [null, AssistantLimitState::SOURCE_NONE] : [$max, AssistantLimitState::SOURCE_MAX];
        } elseif ($max !== null && $max < $candidate) {
            [$candidate, $source] = [$max, AssistantLimitState::SOURCE_MAX];
        }

        return new AssistantLimitState($limit, $value, $default, $max, false, $candidate, $source);
    }

    private function marketCeiling(AssistantLimitEnum $limit, ?AssistantLimit $market): ?float
    {
        if ($limit === AssistantLimitEnum::MonthlyBudgetUsd) {
            return $this->access->monthlyBudgetUsd();
        }

        return self::number($market?->max);
    }

    /**
     * @param  Collection<int, AssistantLimit>  $rows
     */
    private function row(Collection $rows, ?string $tenantId, AssistantLimitEnum $limit): ?AssistantLimit
    {
        return $rows->first(fn (AssistantLimit $row): bool => $row->tenant_id === $tenantId && $row->limit_key === $limit->value);
    }

    private function tenantId(): string
    {
        $tenantId = $this->workspace->id();

        if ($tenantId === null) {
            throw new RuntimeException('AssistantSettings corre dentro de la tenancy del market.');
        }

        return $tenantId;
    }
}
