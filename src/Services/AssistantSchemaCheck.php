<?php

namespace SchoolAid\Assistant\Services;

use Illuminate\Support\Facades\Schema;

/**
 * Dice si el tenant en curso ya tiene las tablas del asistente y su última columna (`assistant_confirmations.undoes_confirmation_id`). Existe como clase aparte para
 * que los tests lo sustituyan: borrar tablas en un test haría commit de la transacción.
 */
class AssistantSchemaCheck
{
    public function tenantHasTables(): bool
    {
        return Schema::hasTable('assistant_conversations')
            && Schema::hasColumn('assistant_confirmations', 'undoes_confirmation_id');
    }
}
