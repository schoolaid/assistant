<?php

namespace SchoolAid\Assistant\Contracts;

use Laravel\Mcp\Server\Tool;

/**
 * De dónde salen las manos del asistente: las tools del servidor MCP de la app, que el puente
 * ejecuta en proceso. El motor no conoce el servidor ni sus clases base: pregunta aquí.
 */
interface ToolSource
{
    /**
     * Las tools que registra el servidor MCP de la app.
     *
     * @return list<class-string<Tool>>
     */
    public function toolClasses(): array;

    /**
     * Lecturas propias del asistente que el servidor no registra. Ninguna puede llamarse como una
     * del servidor.
     *
     * @return list<class-string<Tool>>
     */
    public function extraToolClasses(): array;

    /**
     * Las instrucciones del servidor, tal como las leen sus clientes MCP externos.
     */
    public function instructions(): string;

    /**
     * La tool escribe: devuelve una vista previa con un token y solo se ejecuta al confirmarla.
     */
    public function isWrite(Tool $tool): bool;

    /**
     * Las clases de acción de una escritura que reúne varias acciones, cada una con su grupo en
     * `assistant.subscription_action_groups`; null si la tool es una sola acción.
     *
     * @return list<string>|null
     */
    public function actionClasses(Tool $tool): ?array;

    /**
     * La clase de la acción que pide la llamada, o null si la tool no la tiene.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function actionClassOf(Tool $tool, array $arguments): ?string;
}
