<?php

namespace SchoolAid\Assistant\Services\Evaluation;

use SchoolAid\Assistant\Services\Anthropic\AnthropicSettings;
use Closure;
use Illuminate\Http\Client\Factory;
use Psr\Http\Message\RequestInterface;
use WeakMap;

/**
 * Durante el eval, toda llamada del cliente Http de Laravel a un host fuera de {@see self::allowedHosts()} falla con
 * {@see EvalHttpBlocked} en vez de salir: lo síncrono de una escritura confirmada (una pasarela, un webhook, Reverb,
 * una integración) falla a la vista dentro de la tool, que lo devuelve como error. Pasan la API de Anthropic y el
 * Meilisearch del índice de conocimiento (de SAID, solo lectura en una búsqueda), para que `search-knowledge`
 * funcione. Lo que no pasa por `Http` (el SDK de Infile, Kafka) no se ve aquí: de eso se encargan {@see EvalFelCheck} y
 * `notifier.enabled` = false.
 *
 * Es un middleware global del Factory, puesto una sola vez por instancia ({@see self::for()}), que solo actúa dentro de
 * {@see self::run()}: al salir, el cliente queda como estaba (el middleware sigue en la lista, sin efecto).
 */
final class EvalHttpGuard
{
    /**
     * @var WeakMap<Factory, self>|null
     */
    private static ?WeakMap $guards = null;

    /**
     * @var list<string>|null  null = fuera de {@see self::run()}, sin efecto
     */
    private ?array $allowedHosts = null;

    /**
     * @var list<string>
     */
    private array $blocked = [];

    private function __construct()
    {
    }

    /**
     * El guardia del Factory: lo crea e instala la primera vez.
     */
    public static function for(Factory $factory): self
    {
        self::$guards ??= new WeakMap();

        if (! isset(self::$guards[$factory])) {
            $guard = new self();
            $factory->globalMiddleware($guard->middleware());
            self::$guards[$factory] = $guard;
        }

        return self::$guards[$factory];
    }

    /**
     * Los hosts que pasan durante el eval: el de `prism.providers.anthropic.url` y el de `scout.meilisearch.host`.
     *
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        $hosts = array_map(
            fn (mixed $url): string => strtolower((string) parse_url((string) $url, PHP_URL_HOST)),
            [AnthropicSettings::url(), config('scout.meilisearch.host')],
        );

        return array_values(array_unique(array_filter($hosts, fn (string $host): bool => $host !== '')));
    }

    /**
     * @template TResult
     *
     * @param  list<string>  $allowedHosts
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(array $allowedHosts, Closure $callback): mixed
    {
        $previous = $this->allowedHosts;
        $this->allowedHosts = array_map('strtolower', $allowedHosts);

        try {
            return $callback();
        } finally {
            $this->allowedHosts = $previous;
        }
    }

    /**
     * Los hosts bloqueados desde la última vez que se leyeron, en orden y sin repetir.
     *
     * @return list<string>
     */
    public function pullBlockedHosts(): array
    {
        $blocked = array_values(array_unique($this->blocked));
        $this->blocked = [];

        return $blocked;
    }

    private function middleware(): Closure
    {
        return fn (callable $handler): Closure => function (RequestInterface $request, array $options) use ($handler): mixed {
            $host = strtolower($request->getUri()->getHost());

            if ($this->allowedHosts !== null && ! in_array($host, $this->allowedHosts, true)) {
                $this->blocked[] = $host;

                throw new EvalHttpBlocked($host, $this->allowedHosts);
            }

            return $handler($request, $options);
        };
    }
}
