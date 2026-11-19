<?php

namespace SchoolAid\Assistant\Contracts;

use SchoolAid\Assistant\Services\Entities\AssistantEntityRef;

/**
 * Busca, por texto, los registros que la persona puede adjuntar a su mensaje (el selector `@` del panel). La app
 * conoce sus modelos y decide qué tipos son adjuntables y por qué columnas se buscan.
 *
 * No necesita revisar permisos ni sucursales: el motor pasa lo que devuelve por
 * {@see \SchoolAid\Assistant\Services\Entities\AssistantMentions::labelled()} y descarta lo que no se puede ver.
 * Ojo: el motor filtra después del límite de la app, así que una app que no prefiltra por permisos puede devolver menos
 * de `$limit` resultados visibles por grupo (incluso ninguno, aunque existan coincidencias visibles).
 */
interface EntitySearch
{
    /**
     * Los registros que coinciden con `$query`, a lo más `$limit` por grupo, en el orden en que el panel los muestra.
     * `group` es el nombre del grupo ya traducido por la app («Familias», «Órdenes»…).
     * El motor no recorta lo que devuelve más allá de quitar repetidos y lo que no se puede ver: respetar `$limit` le
     * toca a la app.
     *
     * @return list<array{ref: AssistantEntityRef, group: string}>
     */
    public function search(string $query, int $limit): array;
}
