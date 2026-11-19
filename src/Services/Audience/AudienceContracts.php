<?php

namespace SchoolAid\Assistant\Services\Audience;

use SchoolAid\Assistant\Enums\AssistantAudienceEnum;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Services\Agent\AssistantTurnRunner;
use SchoolAid\Assistant\Services\AssistantMessageSender;
use SchoolAid\Assistant\Services\ConfirmationExecutor;
use Closure;
use LogicException;
use ReflectionClass;
use ReflectionParameter;

/**
 * Resuelve las piezas del motor (runner, sender, executor) según la audiencia de la conversación.
 *
 * El staff (y una conversación que ya no existe) siempre sale de `app()->make($class)`, sin overrides: es el camino
 * de hoy y nunca se puede reemplazar (registrar `staff` lanza). Cualquier otra audiencia la registra un host con
 * {@see self::register()}: un closure que devuelve los overrides de constructor por nombre de parámetro, y que corre
 * en cada {@see self::make()} (objetos frescos por conversación y turno, sin caché).
 *
 * {@see self::CONTRACTS} es el mapa por clase de los parámetros que una audiencia no staff DEBE aportar; sus nombres
 * son parte del contrato del paquete: renombrar un parámetro de constructor es una versión nueva del paquete. Falla
 * cerrado con `LogicException` si una llave del mapa ya no es parámetro de la clase (renombre), si el host aporta una
 * llave que ningún mapa conoce o si le falta alguna: nunca se cae en silencio al contrato del staff. `exports` está
 * porque `AssistantExportRequester` inyecta el `ToolBridge` y el catálogo del contenedor (los del staff), y
 * `undoOffer` porque `UndoOffer` inyecta el `ToolBridge` y los `WritePreviews` del contenedor.
 */
class AudienceContracts
{
    /**
     * @var array<class-string, list<string>>
     */
    private const CONTRACTS = [
        AssistantTurnRunner::class => ['tools', 'scope', 'instructions', 'previews', 'exports'],
        AssistantMessageSender::class => ['instructions', 'scope'],
        ConfirmationExecutor::class => ['tools', 'scope', 'catalog', 'undoOffer'],
    ];

    /**
     * Parámetros de constructor neutrales a la audiencia: el contenedor los resuelve igual para todas. Existe para que
     * una dependencia nueva del motor obligue a decidir si es del contrato de la audiencia o neutral: un parámetro que
     * no está en {@see self::CONTRACTS} ni aquí hace fallar a una audiencia no staff. `mentionLabels` es neutral:
     * `AssistantMentions` resuelve los registros con el `EntityLabels` del contenedor, igual que las tarjetas y los
     * registros de la conversación sin importar la audiencia, y ningún contrato de audiencia incluye etiquetar
     * entidades; si el etiquetador no puede autorizar a esa audiencia, todo adjunto es irresoluble y el mensaje se
     * rechaza con 422 (falla cerrado). `summary` es neutral: `ConversationSummary` no recibe nada en su constructor y
     * resume con el pedido que el propio runner arma, así que las instrucciones y las tools son las de la audiencia.
     *
     * @var array<class-string, list<string>>
     */
    private const NEUTRAL = [
        AssistantTurnRunner::class => ['client', 'budget', 'transcript', 'summary'],
        AssistantMessageSender::class => ['budget', 'limit', 'settings', 'mentionLabels'],
        ConfirmationExecutor::class => ['mark', 'limit'],
    ];

    /**
     * @var array<string, Closure(): array<string, object>>
     */
    private array $registry = [];

    /**
     * @param  Closure(): array<string, object>  $overrides
     */
    public function register(string $audience, Closure $overrides): void
    {
        if ($audience === AssistantAudienceEnum::Staff->value) {
            throw new LogicException('La audiencia staff no se puede reemplazar: siempre se resuelve desde el contenedor.');
        }

        $this->registry[$audience] = $overrides;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function make(string $class, ?AssistantConversation $conversation): object
    {
        $audience = $conversation?->audience->value ?? AssistantAudienceEnum::Staff->value;

        if ($audience === AssistantAudienceEnum::Staff->value) {
            return app()->make($class);
        }

        if (! isset($this->registry[$audience])) {
            throw UnknownAssistantAudience::forAudience($audience);
        }

        $overrides = ($this->registry[$audience])();
        $this->assertContract($class, $audience, array_keys($overrides));

        return app()->make($class, array_intersect_key($overrides, array_flip(self::CONTRACTS[$class])));
    }

    /**
     * @param  class-string  $class
     * @param  list<string|int>  $keys
     */
    private function assertContract(string $class, string $audience, array $keys): void
    {
        if (! isset(self::CONTRACTS[$class])) {
            throw new LogicException("La clase {$class} no está en el mapa de contratos de audiencia.");
        }

        $known = array_unique(array_merge(...array_values(self::CONTRACTS)));
        $parameters = array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), (new ReflectionClass($class))->getConstructor()?->getParameters() ?? []);

        foreach (self::CONTRACTS[$class] as $key) {
            if (! in_array($key, $parameters, true)) {
                throw new LogicException("«{$key}» del contrato de audiencia ya no es parámetro del constructor de {$class}.");
            }

            if (! in_array($key, $keys, true)) {
                throw new LogicException("La audiencia «{$audience}» no aporta «{$key}», que {$class} exige.");
            }
        }

        foreach ($parameters as $parameter) {
            if (! in_array($parameter, self::CONTRACTS[$class], true) && ! in_array($parameter, self::NEUTRAL[$class], true)) {
                throw new LogicException("«{$parameter}» es parámetro nuevo del constructor de {$class}: decide si es del contrato de audiencia o neutral.");
            }
        }

        foreach ($keys as $key) {
            if (! in_array($key, $known, true)) {
                throw new LogicException("El override «{$key}» de la audiencia «{$audience}» no es parte del contrato de ninguna clase del motor.");
            }
        }
    }
}
