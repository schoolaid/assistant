<?php

namespace SchoolAid\Assistant\Contracts;

use SchoolAid\Assistant\Services\Tools\ToolCallResult;

/**
 * La bitácora de llamadas a tools de la app: cada llamada del asistente deja la misma fila que
 * dejaría por el servidor MCP.
 */
interface ToolCallLog
{
    /**
     * Lo que se escribe en lugar de un token de confirmación, en la bitácora y en lo que vuelve
     * al modelo.
     */
    public const HIDDEN = '[oculto]';

    /**
     * @param  array<string, mixed>  $arguments  tal como llegaron a la tool
     * @param  string|null  $exceptionDetail  «Clase: mensaje» de una excepción inesperada
     */
    public function record(string $tool, array $arguments, string $runId, ToolCallResult $result, int $durationMs, ?string $exceptionDetail): void;
}
