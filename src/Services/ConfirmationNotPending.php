<?php

namespace SchoolAid\Assistant\Services;

use Symfony\Component\HttpFoundation\Response;

/**
 * La confirmación ya se decidió (confirmada, rechazada, vencida o fallida) o otra request la está ejecutando: un
 * segundo clic no vuelve a escribir.
 */
class ConfirmationNotPending extends AssistantRequestRefused
{
    public const CODE = 'confirmation_not_pending';

    public function __construct()
    {
        parent::__construct(self::CODE, Response::HTTP_CONFLICT);
    }
}
