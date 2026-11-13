<?php

namespace SchoolAid\Assistant\Services\Tools;

/**
 * Las manos del asistente: las tools del MCP admin ejecutadas en proceso como el usuario del
 * panel. Se usa siempre **dentro** de {@see PanelContextScope::run()}, que fija el usuario, su
 * sucursal y el principal de escritura del asistente.
 */
interface ToolBridge
{
    /**
     * Definiciones para el parámetro `tools` de la Messages API, ordenadas por `name`: solo las
     * tools que el catálogo del asistente carga ({@see AssistantToolCatalog}) y que el usuario
     * puede usar, sin los argumentos con los que se confirma una escritura. Con
     * `assistant.tool_search.enabled`, las que no son del núcleo llevan `defer_loading: true`.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>, defer_loading?: true}>
     */
    public function definitions(): array;

    /**
     * Ejecuta la tool y deja su fila en `mcp_tool_logs` con `$runId`. Con `$fromModel` (la llamada
     * la pidió el modelo) se descartan `confirmationToken` e `idempotencyKey`: el modelo nunca
     * confirma una escritura. Sin él (el clic de Confirmar del humano) el token pasa. Una escritura
     * sin grupo no se llama, y una vista previa que toca una tarjeta vuelve con `blockedByCard` y
     * sin token.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function call(string $tool, array $arguments, string $runId, bool $fromModel = true): ToolCallResult;
}
