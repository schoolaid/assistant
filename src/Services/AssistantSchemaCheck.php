<?php

namespace SchoolAid\Assistant\Services;

use SchoolAid\Assistant\Support\AssistantNaming;
use Illuminate\Support\Facades\Schema;

/**
 * Dice si el tenant en curso ya tiene las tablas del asistente y su última columna (`assistant_confirmations.undoes_confirmation_id`). Existe como clase aparte para
 * que los tests lo sustituyan: borrar tablas en un test haría commit de la transacción.
 */
class AssistantSchemaCheck
{
    public function tenantHasTables(): bool
    {
        return Schema::hasTable(AssistantNaming::table('conversations'))
            && Schema::hasColumn(AssistantNaming::table('confirmations'), 'undoes_confirmation_id');
    }
}
