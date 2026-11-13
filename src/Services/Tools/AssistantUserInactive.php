<?php

namespace SchoolAid\Assistant\Services\Tools;

use RuntimeException;

/**
 * El usuario del contexto está dado de baja (`users.active = 0`): el asistente no corre tools a su
 * nombre, igual que EnsureUserIsActive corta su sesión del panel y su acceso a `/mcp/admin`. Lo
 * lanza {@see PanelContextScope::run()} antes de entrar.
 */
class AssistantUserInactive extends RuntimeException
{
    public function __construct(public readonly int $userId)
    {
        parent::__construct("El usuario {$userId} está dado de baja: el asistente no corre tools a su nombre.");
    }
}
