<?php

namespace SchoolAid\Assistant\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Doble llave del asistente: la plataforma lo enciende para el espacio de trabajo y el espacio
 * decide, por rol, quién lo usa y quién lo configura.
 */
interface AccessGate
{
    public const DISABLED = 'assistant_disabled';

    public const PERMISSION_DENIED = 'assistant_permission_denied';

    /**
     * No sale de denialFor(): lo decide el medidor de consumo del turno.
     */
    public const BUDGET_EXCEEDED = 'assistant_budget_exceeded';

    /**
     * Tampoco sale de denialFor(): el usuario guardado en el turno quedó dado de baja antes de confirmar.
     */
    public const USER_INACTIVE = 'assistant_user_inactive';

    /**
     * Tampoco sale de denialFor(): la persona llegó a su tope personal del mes (AssistantBudget::isUserExceeded()).
     */
    public const USER_BUDGET_EXCEEDED = 'assistant_user_budget_exceeded';

    /**
     * Tampoco sale de denialFor(): Anthropic rechazó la llave propia del market y no se usa la de SAID en su lugar.
     */
    public const API_KEY_INVALID = 'assistant_api_key_invalid';

    public function isEnabledForTenant(): bool;

    /**
     * Tope mensual en USD que la plataforma le dio al espacio de trabajo; 0 si no tiene.
     */
    public function monthlyBudgetUsd(): float;

    /**
     * Motivo del rechazo (`DISABLED` / `PERMISSION_DENIED`), o null si el usuario puede usar el
     * asistente.
     */
    public function denialFor(?Authenticatable $user): ?string;

    /**
     * La persona puede cambiar la configuración del asistente en su espacio de trabajo.
     */
    public function canManageSettings(?Authenticatable $user): bool;

    /**
     * La persona puede cambiar lo que la plataforma fija para todos los espacios de trabajo.
     */
    public function canManagePlatformSettings(?Authenticatable $user): bool;

    public function message(string $code): string;
}
