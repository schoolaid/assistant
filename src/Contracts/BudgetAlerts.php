<?php

namespace SchoolAid\Assistant\Contracts;

/**
 * A quién se avisa cuando el consumo del mes cruza su umbral. El medidor decide cuándo (una vez
 * por mes cada aviso); la app decide a quién y por dónde.
 */
interface BudgetAlerts
{
    /**
     * Aviso a la plataforma (SAID): el espacio de trabajo se acerca al tope que ella paga.
     */
    public function warnPlatform(int $usageId, string $period, float $costUsd, float $capUsd): void;

    /**
     * Aviso al espacio de trabajo: a quienes pueden cambiar la configuración del asistente.
     */
    public function warnWorkspace(int $usageId, string $period, float $costUsd, float $capUsd): void;
}
