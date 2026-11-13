<?php

namespace SchoolAid\Assistant\Services\Settings;

use SchoolAid\Assistant\Support\PanelUsers;
use SchoolAid\Assistant\Contracts\AccessGate;
use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Models\AssistantUsage;
use SchoolAid\Assistant\Models\AssistantUserUsage;
use SchoolAid\Assistant\Services\Usage\AssistantBudget;

/**
 * Lo que ven el panel del market y central de la configuración del asistente de un market (dentro de su tenancy):
 * la llave (nunca la llave en sí), los límites ya resueltos y el consumo. Formas en snake_case como el resto del
 * web-api del asistente.
 */
class AssistantSettingsPresenter
{
    public function __construct(
        private readonly AssistantSettings $settings,
        private readonly AssistantApiKeys $keys,
        private readonly AssistantBudget $budget,
        private readonly AccessGate $access,
        private readonly Workspace $workspace,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function market(): array
    {
        $limits = $this->settings->limits();
        $period = $this->budget->currentPeriod();
        $usage = AssistantUsage::query()->where('tenant_id', $this->workspace->id())->where('period', $period)->first();

        return [
            'enabled' => $this->access->isEnabledForTenant(),
            'key' => [
                'source' => $this->settings->keySource()->value,
                'own_key_allowed' => $this->settings->ownKeyAllowed(),
                'own_key' => $this->keys->state($this->settings->ownKey()),
            ],
            'limits' => array_map(fn (AssistantLimitState $state): array => $state->toArray(), $limits),
            'usage' => [
                'period' => $period,
                'used_usd' => round((float) $usage?->cost_usd, 4),
                'own_key_used_usd' => round((float) $usage?->own_key_cost_usd, 4),
                'cap_usd' => $limits['monthly_budget_usd']->toArray()['effective'],
            ],
        ];
    }

    /**
     * Lo del market más lo que central fijó para él.
     *
     * @return array<string, mixed>
     */
    public function central(): array
    {
        return [
            'tenant' => ['id' => (string) $this->workspace->id(), 'name' => (string) $this->workspace->name()],
            ...$this->market(),
            'overrides' => $this->settings->overrides(),
        ];
    }

    /**
     * Consumo de un mes por usuario, de mayor a menor.
     *
     * @return array{period: string, used_usd: float, cap_usd: float|int|null, user_cap_usd: float|int|null, users: list<array<string, mixed>>}
     */
    public function usage(string $period): array
    {
        $rows = AssistantUserUsage::query()
            ->where('tenant_id', $this->workspace->id())
            ->where('period', $period)
            ->orderByDesc('cost_usd')
            ->get();
        $names = PanelUsers::query()->whereIn('id', $rows->pluck('user_id'))->pluck('name', 'id');
        $limits = $this->settings->limits();

        return [
            'period' => $period,
            'used_usd' => round((float) AssistantUsage::query()->where('tenant_id', $this->workspace->id())->where('period', $period)->value('cost_usd'), 4),
            'cap_usd' => $limits['monthly_budget_usd']->toArray()['effective'],
            'user_cap_usd' => $limits['user_monthly_budget_usd']->toArray()['effective'],
            'users' => $rows->map(fn (AssistantUserUsage $row): array => [
                'user' => ['id' => $row->user_id, 'name' => $names[$row->user_id] ?? null],
                'responses' => $row->responses,
                'used_usd' => round((float) $row->cost_usd, 4),
            ])->values()->all(),
        ];
    }
}
