<?php

namespace SchoolAid\Assistant\Enums;

/**
 * Con qué llave se llama a Anthropic para un market: la propia (el costo es del market y no aplican los topes en USD
 * de SAID) o la de SAID (la general de central o, sin ella, `ANTHROPIC_API_KEY`).
 */
enum AssistantKeySourceEnum: string
{
    case Own = 'own';
    case Said = 'said';
}
