<?php

namespace SchoolAid\Assistant\Services\Settings;

use SchoolAid\Assistant\Contracts\PlatformGrant;
use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Enums\AssistantLimitEnum;
use SchoolAid\Assistant\Models\AssistantLimit;
use SchoolAid\Assistant\Models\AssistantTenantSetting;
use Illuminate\Support\Facades\DB;

/**
 * Escribe los límites del asistente. Cada método valida todo antes de escribir: si algo no cabe, lanza
 * {@see AssistantSettingsRejected} con el campo y no guarda nada. Los rangos de cada valor los validan los FormRequest
 * ({@see AssistantLimitEnum::rules()}); aquí van las reglas que dependen de lo que fijó central.
 */
class AssistantSettingsUpdater
{
    public function __construct(
        private readonly AssistantSettings $settings,
        private readonly Workspace $workspace,
    ) {
    }

    /**
     * Lo que elige el market (dentro de su tenancy). Solo cambian las claves que trae `$values`; null = usar el valor
     * por omisión. Un límite bloqueado no se cambia (sí se acepta el mismo valor que ya tenía, para que el panel pueda
     * mandar el formulario entero) y uno con techo no lo pasa.
     *
     * @param  array<string, float|int|null>  $values  por clave de límite
     *
     * @throws AssistantSettingsRejected
     */
    public function updateMarketLimits(array $values): void
    {
        $states = $this->settings->limits();
        $errors = [];

        foreach ($values as $key => $value) {
            $state = $states[$key];
            $value = AssistantSettings::number($value);

            if ($state->locked && $value !== $state->value) {
                $errors[$key] = AssistantSettingsRejected::on($key, AssistantSettingsRejected::LIMIT_LOCKED)->errors[$key];
            } elseif ($value !== null && $state->max !== null && $value > $state->max) {
                $errors[$key] = AssistantSettingsRejected::on($key, AssistantSettingsRejected::LIMIT_ABOVE_MAX, ['max' => $state->toArray()['max']])->errors[$key];
            }
        }

        if ($errors !== []) {
            $code = collect(array_keys($errors))->contains(fn (string $key): bool => $states[$key]->locked)
                ? AssistantSettingsRejected::LIMIT_LOCKED
                : AssistantSettingsRejected::LIMIT_ABOVE_MAX;

            throw new AssistantSettingsRejected($code, $errors);
        }

        $tenantId = (string) $this->workspace->id();

        DB::connection((new AssistantLimit)->getConnectionName())->transaction(function () use ($values, $tenantId): void {
            foreach ($values as $key => $value) {
                $this->limitRow($tenantId, $key)->fill(['value' => AssistantSettings::number($value)])->save();
            }
        });
    }

    /**
     * Lo general de central: por límite, `{default?, max?}`. El tope mensual no lleva techo general
     * ({@see AssistantSettings::hasGeneralCeiling()}) y el aviso no lleva techo.
     *
     * @param  array<string, array{default?: float|int|null, max?: float|int|null}>  $values
     */
    public function updateGeneralLimits(array $values): void
    {
        DB::connection((new AssistantLimit)->getConnectionName())->transaction(function () use ($values): void {
            foreach ($values as $key => $limit) {
                $row = $this->limitRow(null, $key);
                $enum = AssistantLimitEnum::from($key);

                if (array_key_exists('default', $limit)) {
                    $row->value = AssistantSettings::number($limit['default']);
                }

                if (array_key_exists('max', $limit) && AssistantSettings::hasGeneralCeiling($enum)) {
                    $row->max = AssistantSettings::number($limit['max']);
                }

                $row->save();
            }
        });
    }

    /**
     * Lo de central para un market: si puede traer su llave y, por límite, `{max?, lock?}` (`lock` null quita el
     * bloqueo; `{value}` lo pone). El techo del tope mensual es el parámetro del tenant y se escribe con
     * {@see PlatformGrant}, que también lleva el interruptor (y lanza la excepción de la app si no se puede).
     *
     * @param  array<string, array{max?: float|int|null, lock?: array{value: float|int|null}|null}>  $overrides
     */
    public function updateTenant(string $tenantId, ?bool $enabled, ?bool $ownKeyAllowed, array $overrides): void
    {
        $grant = app(PlatformGrant::class);
        $monthlyMax = $overrides[AssistantLimitEnum::MonthlyBudgetUsd->value] ?? [];

        if ($ownKeyAllowed !== null) {
            AssistantTenantSetting::query()->updateOrCreate(['tenant_id' => $tenantId], ['own_key_allowed' => $ownKeyAllowed]);
        }

        if ($enabled !== null || array_key_exists('max', $monthlyMax)) {
            $current = $grant->current($tenantId);
            $grant->apply(
                $tenantId,
                $enabled ?? $current['enabled'],
                array_key_exists('max', $monthlyMax) ? (float) ($monthlyMax['max'] ?? 0) : $current['monthlyBudgetUsd'],
            );
        }

        DB::connection((new AssistantLimit)->getConnectionName())->transaction(function () use ($overrides, $tenantId): void {
            foreach ($overrides as $key => $override) {
                $enum = AssistantLimitEnum::from($key);
                $row = $this->limitRow($tenantId, $key);

                if (array_key_exists('max', $override) && $enum->hasCeiling() && $enum !== AssistantLimitEnum::MonthlyBudgetUsd) {
                    $row->max = AssistantSettings::number($override['max']);
                }

                if (array_key_exists('lock', $override)) {
                    $row->locked = $override['lock'] !== null;
                    $row->locked_value = $override['lock'] === null ? null : AssistantSettings::number($override['lock']['value'] ?? null);
                }

                $row->save();
            }
        });
    }

    private function limitRow(?string $tenantId, string $key): AssistantLimit
    {
        return AssistantLimit::query()
            ->when($tenantId === null, fn ($query) => $query->whereNull('tenant_id'), fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('limit_key', $key)
            ->first() ?? new AssistantLimit(['tenant_id' => $tenantId, 'limit_key' => $key]);
    }
}
