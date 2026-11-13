<?php

namespace SchoolAid\Assistant\Models\Concerns;

use SchoolAid\Assistant\Support\AssistantNaming;

/**
 * La tabla del modelo lleva el nombre del asistente en la app como prefijo
 * ({@see AssistantNaming::table()}). El modelo declara su sufijo en la constante `TABLE`.
 */
trait HasAssistantTable
{
    public function getTable(): string
    {
        return $this->table ?? AssistantNaming::table(static::TABLE);
    }
}
