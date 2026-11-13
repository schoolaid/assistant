<?php

namespace SchoolAid\Assistant\Services\Agent;

use RuntimeException;

/**
 * El paso ya no es de este worker: su reclamo (`step_started_at`) venció y otro lo retomó, o el
 * recolector lo soltó. Lo lanza {@see AssistantTurnRunner} dentro de la transacción que cierra el
 * paso, para que nada de lo que este worker iba a guardar quede escrito.
 */
class StepClaimLost extends RuntimeException
{
    public function __construct(public readonly int $turnId)
    {
        parent::__construct("El paso del turno {$turnId} lo tiene otro worker.");
    }
}
