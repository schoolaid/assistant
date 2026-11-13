<?php

namespace SchoolAid\Assistant\Enums;

/**
 * Estado de una llave guardada: `Valid` al guardarla (se prueba antes) y `Invalid` cuando Anthropic la rechaza en uso
 * (401/403). Una llave propia `Invalid` no se usa ni se reemplaza por la de SAID: el market la cambia o la quita.
 */
enum AssistantApiKeyStatusEnum: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
}
