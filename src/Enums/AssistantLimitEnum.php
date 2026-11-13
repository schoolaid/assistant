<?php

namespace SchoolAid\Assistant\Enums;

/**
 * Límites del asistente que se configuran por market y desde central
 * ({@see \SchoolAid\Assistant\Services\Settings\AssistantSettings}).
 *
 * - Los de dinero (`isMoney()`) no reciben el valor por omisión ni el techo de SAID cuando el market usa su propia
 *   llave: el costo es del market. El bloqueo de central sí aplica.
 * - `WarningPercent` no tiene techo: central da el valor por omisión y puede bloquearlo.
 * - El techo por market de `MonthlyBudgetUsd` es el parámetro `assistant_monthly_budget_usd` del tenant (el tope que
 *   SAID fijaba antes de esta configuración), no una fila de `assistant_limits`.
 */
enum AssistantLimitEnum: string
{
    case MonthlyBudgetUsd = 'monthly_budget_usd';
    case UserMonthlyBudgetUsd = 'user_monthly_budget_usd';
    case MessagesPerMinute = 'messages_per_minute';
    case WarningPercent = 'warning_percent';

    public function isMoney(): bool
    {
        return in_array($this, [self::MonthlyBudgetUsd, self::UserMonthlyBudgetUsd], true);
    }

    public function hasCeiling(): bool
    {
        return $this !== self::WarningPercent;
    }

    public function isInteger(): bool
    {
        return ! $this->isMoney();
    }

    /**
     * Valor por omisión cuando central todavía no fijó uno: el que tenía el código antes de esta configuración.
     */
    public function builtInDefault(): ?float
    {
        return match ($this) {
            self::MonthlyBudgetUsd, self::UserMonthlyBudgetUsd => null,
            self::MessagesPerMinute => (float) config('assistant.limits.messages_per_minute', 20),
            self::WarningPercent => (float) config('assistant.limits.warning_percent', 80),
        };
    }

    /**
     * Reglas de validación de un valor (del market, por omisión, techo o bloqueo); null = sin valor.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return match ($this) {
            self::MonthlyBudgetUsd, self::UserMonthlyBudgetUsd => ['nullable', 'numeric', 'min:0', 'max:100000'],
            self::MessagesPerMinute => ['nullable', 'integer', 'min:1', 'max:120'],
            self::WarningPercent => ['nullable', 'integer', 'min:1', 'max:100'],
        };
    }
}
