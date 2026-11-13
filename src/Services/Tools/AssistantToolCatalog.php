<?php

namespace SchoolAid\Assistant\Services\Tools;

use SchoolAid\Assistant\Contracts\ToolSource;
use Laravel\Mcp\Server\Tool;

/**
 * Qué tools del MCP admin carga y llama el asistente: una lista permitida (spec del asistente,
 * «Grupos de reversibilidad»: docs/superpowers/specs/2026-11-08-asistente-panel-agente-design.md).
 *
 * - Las lecturas entran sin mapa (grupo 0).
 * - {@see self::extraTools()}: lecturas propias del asistente que el `AdminServer` no registra
 *   (`read-catalog`). {@see self::find()} y las definiciones del puente las suman; {@see self::tools()}
 *   sigue siendo solo del `AdminServer` (de ahí salen las oraciones de sus instrucciones que no valen
 *   en el panel).
 * - Una escritura entra solo con grupo 1 o 2 en `assistant.tool_groups`; `manage-subscription`,
 *   además, con el grupo de la acción que pide en `assistant.subscription_action_groups` (por
 *   clase de acción). Sin grupo, con otro valor o en `assistant.panel_only` (el grupo 3 decidido)
 *   no se carga ni se llama. Así una escritura nueva del AdminServer no llega al asistente hasta
 *   que alguien la clasifica (un test lo exige).
 * - {@see self::isDenied()}: tools que el asistente no carga aunque el AdminServer las tenga.
 * - {@see self::isCore()}: las que van cargadas con la búsqueda de tools; las demás, diferidas.
 *
 * Lo que toca una tarjeta no se decide aquí: lo dice cada tool en su vista previa
 * (`touchesCard`) y el puente no la ofrece para Confirmar.
 */
class AssistantToolCatalog
{
    private const READ_GROUP = 0;

    /**
     * Grupos que el asistente carga: 1 (deshacer en el chat) y 2 (corregible en el panel).
     */
    private const LOADABLE_GROUPS = [1, 2];

    private readonly ToolSource $source;

    /**
     * @param  list<class-string<Tool>>|null  $toolClasses  null = las del servidor MCP de la app ({@see ToolSource::toolClasses()})
     */
    public function __construct(private readonly ?array $toolClasses = null, ?ToolSource $source = null)
    {
        $this->source = $source ?? app(ToolSource::class);
    }

    /**
     * Las tools que el asistente carga: sin las negadas ni las escrituras sin grupo. Instancias
     * nuevas en cada llamada (algunas tools guardan estado de la llamada en curso).
     *
     * @return list<Tool>
     */
    public function tools(): array
    {
        return array_values(array_filter($this->available(), fn (Tool $tool): bool => $this->isLoadable($tool)));
    }

    /**
     * Lecturas del asistente que no son del `AdminServer`, sin las negadas: `read-catalog` (los
     * resources del `AdminServer` como tool, porque el puente solo carga tools). Un test exige que
     * ninguna choque con un nombre del `AdminServer`.
     *
     * @return list<Tool>
     */
    public function extraTools(): array
    {
        $tools = [];

        foreach ($this->source->extraToolClasses() as $toolClass) {
            $tool = app($toolClass);

            if (! $this->isDenied($tool->name())) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * La tool con ese nombre, tenga grupo o no (el puente responde distinto a una sin grupo), o null
     * si no existe o está negada. Busca también en {@see self::extraTools()}.
     */
    public function find(string $name): ?Tool
    {
        foreach ([...$this->available(), ...$this->extraTools()] as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Grupo de la llamada: 0 una lectura, 1 o 2 una escritura con grupo, null si no se llama
     * (escritura sin grupo; en `manage-subscription`, también una acción sin grupo, en
     * `panel_only` o que la tool no tiene).
     *
     * @param  array<string, mixed>  $arguments
     */
    public function groupOf(Tool $tool, array $arguments): ?int
    {
        if (! $this->source->isWrite($tool)) {
            return self::READ_GROUP;
        }

        $group = $this->classified($tool->name(), (array) config('assistant.tool_groups', []));

        if ($group === null || $this->source->actionClasses($tool) === null) {
            return $group;
        }

        $actionClass = $this->source->actionClassOf($tool, $arguments);

        return $actionClass === null ? null : $this->actionGroup($actionClass);
    }

    /**
     * `assistant.denied_tools` y, con `assistant.knowledge.enabled`, `assistant.denied_with_knowledge`
     * (las de la guía que `search-knowledge` reemplaza; `get-help-page` se queda, R-P2-29): sin
     * índice, el agente se queda con la guía de siempre. Solo filtra en el asistente.
     */
    public function isDenied(string $name): bool
    {
        $denied = (array) config('assistant.denied_tools', []);

        if ((bool) config('assistant.knowledge.enabled')) {
            $denied = [...$denied, ...(array) config('assistant.denied_with_knowledge', [])];
        }

        return in_array($name, $denied, true);
    }

    /**
     * Del núcleo (`assistant.core_tools`): con la búsqueda de tools encendida, va sin diferir.
     */
    public function isCore(string $name): bool
    {
        return in_array($name, (array) config('assistant.core_tools', []), true);
    }

    /**
     * Una lectura, o una escritura con grupo; `manage-subscription`, si además alguna de sus
     * acciones tiene grupo (la acción de cada llamada la revisa groupOf()).
     */
    private function isLoadable(Tool $tool): bool
    {
        if (! $this->source->isWrite($tool)) {
            return true;
        }

        if ($this->classified($tool->name(), (array) config('assistant.tool_groups', [])) === null) {
            return false;
        }

        $actionClasses = $this->source->actionClasses($tool);

        if ($actionClasses === null) {
            return true;
        }

        foreach ($actionClasses as $actionClass) {
            if ($this->actionGroup($actionClass) !== null) {
                return true;
            }
        }

        return false;
    }

    private function actionGroup(string $actionClass): ?int
    {
        return $this->classified($actionClass, (array) config('assistant.subscription_action_groups', []));
    }

    /**
     * El grupo 1 o 2 de `$key` en el mapa, o null (sin entrada, otro valor o en `panel_only`).
     *
     * @param  array<string, mixed>  $groups
     */
    private function classified(string $key, array $groups): ?int
    {
        if (in_array($key, (array) config('assistant.panel_only', []), true)) {
            return null;
        }

        $group = $groups[$key] ?? null;

        return in_array($group, self::LOADABLE_GROUPS, true) ? $group : null;
    }

    /**
     * Las tools del servidor sin las negadas.
     *
     * @return list<Tool>
     */
    private function available(): array
    {
        $tools = [];

        foreach ($this->toolClasses ?? $this->source->toolClasses() as $toolClass) {
            $tool = app($toolClass);

            if (! $this->isDenied($tool->name())) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }
}
