<?php

namespace SchoolAid\Assistant\Contracts;

/**
 * Lo que la plataforma (SAID) le concede a un espacio de trabajo: el interruptor del asistente y
 * su tope mensual. Se lee y se escribe desde fuera del espacio, por su id.
 */
interface PlatformGrant
{
    /**
     * @return array{enabled: bool, monthlyBudgetUsd: float}
     */
    public function current(string $workspaceId): array;

    /**
     * Lanza la excepción de la app si ese estado no se puede aplicar (p. ej. encender sin tope).
     */
    public function apply(string $workspaceId, bool $enabled, float $monthlyBudgetUsd): void;
}
