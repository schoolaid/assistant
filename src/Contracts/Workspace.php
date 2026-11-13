<?php

namespace SchoolAid\Assistant\Contracts;

use Closure;

/**
 * El espacio de trabajo donde corre el asistente: lo que aísla los datos de un cliente de SAID de
 * los de otro (en MarketAid, el market; en una app de una sola base, el colegio). El motor del
 * asistente no sabe cómo se aísla: pregunta aquí en cuál está, cómo se llama, cuáles existen y
 * cómo entrar a uno.
 *
 * Lo que es de la plataforma y no de un espacio (llaves, límites, consumo) vive en la conexión
 * de {@see self::sharedConnection()} y lleva el id del espacio en `tenant_id`.
 */
interface Workspace
{
    /**
     * Id del espacio en curso, o null fuera de uno (comandos de la plataforma).
     */
    public function id(): ?string;

    /**
     * Nombre visible del espacio en curso; su id si no tiene nombre; null fuera de uno.
     */
    public function name(): ?string;

    /**
     * Ids de todos los espacios, leídos desde fuera de cualquiera.
     *
     * @return list<string>
     */
    public function ids(): array;

    /**
     * Corre el callback dentro del espacio y, al salir (también si lanza), deja activo el que
     * había antes, o ninguno.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws WorkspaceNotFound
     */
    public function run(string $id, Closure $callback): mixed;

    /**
     * Conexión de las tablas de la plataforma, o null si son la conexión por omisión.
     */
    public function sharedConnection(): ?string;
}
