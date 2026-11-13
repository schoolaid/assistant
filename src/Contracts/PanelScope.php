<?php

namespace SchoolAid\Assistant\Contracts;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Corre código como lo haría la persona en el panel: su usuario autenticado, su alcance (sucursal,
 * colegio…) y el principal de escritura del asistente, sin una request HTTP.
 */
interface PanelScope
{
    /**
     * El contexto de la persona en la request en curso.
     */
    public function capture(Authenticatable $user): PanelSnapshot;

    /**
     * El contexto guardado en un turno.
     *
     * @param  array<string, mixed>  $data  lo que devolvió {@see PanelSnapshot::toArray()}
     */
    public function restore(array $data): PanelSnapshot;

    /**
     * Corre el callback con ese contexto y, al salir (también si lanza), deja todo como estaba.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(PanelSnapshot $context, Closure $callback): mixed;

    /**
     * Corre una llamada a una tool y, al salir, deshace lo que la tool haya cambiado del alcance:
     * por el servidor MCP cada llamada es una request nueva; aquí todo el turno comparte el contexto.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $call
     * @return TResult
     */
    public function isolateCall(Closure $call): mixed;
}
