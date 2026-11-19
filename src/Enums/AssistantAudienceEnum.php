<?php

namespace SchoolAid\Assistant\Enums;

/**
 * Quién conversa con el asistente: el personal del panel o un cliente del market.
 */
enum AssistantAudienceEnum: string
{
    case Staff = 'staff';
    case Customer = 'customer';
}
