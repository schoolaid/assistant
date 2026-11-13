<?php

namespace SchoolAid\Assistant\Services\Evaluation;

use RuntimeException;

/**
 * El market no tiene un registro que sirva para un dato del pedido (`{orden_pendiente}`…): la tarea se salta.
 */
class TaskEvalFixtureMissing extends RuntimeException
{
    public function __construct(public readonly string $placeholder, string $reason)
    {
        parent::__construct("sin datos para {{$placeholder}}: {$reason}");
    }
}
