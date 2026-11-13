<?php

namespace SchoolAid\Assistant\Contracts;

use RuntimeException;

class WorkspaceNotFound extends RuntimeException
{
    public function __construct(public readonly string $workspaceId)
    {
        parent::__construct("No existe el espacio de trabajo «{$workspaceId}».");
    }
}
