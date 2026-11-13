<?php

namespace SchoolAid\Assistant\Contracts;

use SchoolAid\Assistant\Services\Entities\AssistantEntityRef;

/**
 * Cómo se ven en el panel los registros que una conversación tocó: la app conoce sus modelos, sus
 * resources y qué texto los identifica.
 */
interface EntityLabels
{
    /**
     * En el orden recibido, sin los que no se encuentran o no tienen pantalla en el panel.
     *
     * @param  list<AssistantEntityRef>  $refs
     * @return list<array{type: string, resource: string, id: int, date: string|null, label: string, detail: string|null, created: bool}>
     */
    public function label(array $refs): array;

    /**
     * Como {@see self::label()}, varias listas a la vez con una consulta por tipo de registro.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, list<AssistantEntityRef>>  $lists
     * @return array<TKey, list<array{type: string, resource: string, id: int, date: string|null, label: string, detail: string|null, created: bool}>>
     */
    public function labelEach(array $lists): array;
}
