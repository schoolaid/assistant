<?php

namespace SchoolAid\Assistant\Services\Audience;

use RuntimeException;

/**
 * La conversación es de una audiencia que ningún host registró en {@see AudienceContracts}.
 */
class UnknownAssistantAudience extends RuntimeException
{
    public static function forAudience(string $audience): self
    {
        return new self("La audiencia «{$audience}» del asistente no está registrada.");
    }
}
