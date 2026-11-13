<?php

namespace SchoolAid\Assistant\Knowledge;

use SchoolAid\Assistant\Support\AssistantNaming;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Normalizer;
use RuntimeException;

/**
 * Índice híbrido (texto + vectores locales) del conocimiento del asistente en Meilisearch.
 */
class MeilisearchKnowledgeIndex implements KnowledgeSearch
{
    private const BATCH_SIZE = 200;

    private const PAGE_SIZE = 1000;

    private const EXCERPT_LENGTH = 600;

    /**
     * Tope de bytes de la plantilla que se embebe, por encima del fragmento más largo (la
     * plantilla corta a 250 palabras: ~2 100 bytes medidos). Meilisearch 1.45 aplica
     * `documentTemplateMaxBytes` (400 por defecto) al re-embeber tras un cambio de settings pero no
     * al agregar documentos: con el default, el mismo contenido da vectores distintos según cómo
     * entró (medido 2026-11-09, conocimiento.md). Con el tope arriba de todo fragmento, los dos
     * caminos embeben el mismo texto.
     */
    private const TEMPLATE_MAX_BYTES = 4000;

    private const STOP_WORDS = ['a', 'al', 'algo', 'como', 'cómo', 'con', 'cual', 'cuál', 'de', 'del', 'el', 'en', 'es', 'esta', 'este', 'esto', 'hay', 'la', 'las', 'le', 'les', 'lo', 'los', 'me', 'mi', 'mis', 'no', 'para', 'pero', 'por', 'que', 'qué', 'se', 'si', 'sí', 'su', 'sus', 'un', 'una', 'uno', 'y', 'ya', 'yo', 'puedo', 'hago', 'hacer'];

    /**
     * Crea el índice si falta y aplica los settings; espera cada tarea. Si el embebedor guardado
     * no es el configurado (otro modelo, plantilla o tope), primero lo quita: Meilisearch no
     * re-embebe al cambiar solo el tope de bytes, y un cambio de modelo re-embebe con reglas
     * distintas a las de agregar documentos. Quitarlo y volverlo a poner deja todos los vectores
     * hechos de una vez y con la misma regla.
     *
     * Si lo guardado ya es lo configurado no manda nada: la tarea de settings carga el embebedor
     * otra vez y, con el proceso de Meilisearch ya cargado tras indexar, lo pasó del límite de
     * memoria dos veces en producción (2026-11-11, notas-y-issues.md).
     */
    public function configure(): void
    {
        $index = $this->client()->get("/indexes/{$this->uid()}");

        if ($index->status() === 404) {
            $this->waitTask($this->client()->post('/indexes', ['uid' => $this->uid(), 'primaryKey' => 'id'])->throw()->json('taskUid'));
        } elseif (! $index->successful()) {
            throw new RuntimeException("Meilisearch: HTTP {$index->status()} al leer el índice {$this->uid()}");
        }

        $name = AssistantNaming::config('knowledge.embedder.name');
        $embedder = $this->embedderSettings();
        $stored = $this->client()->get("/indexes/{$this->uid()}/settings/embedders")->throw()->json($name);

        if (is_array($stored) && array_intersect_key($stored, $embedder) != $embedder) {
            $this->waitTask($this->client()->patch("/indexes/{$this->uid()}/settings", ['embedders' => [$name => null]])->throw()->json('taskUid'));
        }

        $embedderMatches = is_array($stored) && array_intersect_key($stored, $embedder) == $embedder;

        if ($embedderMatches && $this->storedSettingsMatch()) {
            return;
        }

        $task = $this->client()->patch("/indexes/{$this->uid()}/settings", [
            ...$this->indexSettings(),
            'embedders' => [$name => $embedder],
        ])->throw()->json('taskUid');

        $this->waitTask($task);
    }

    /**
     * @return array{searchableAttributes: list<string>, filterableAttributes: list<string>, stopWords: list<string>, localizedAttributes: list<array{attributePatterns: list<string>, locales: list<string>}>}
     */
    private function indexSettings(): array
    {
        return [
            'searchableAttributes' => ['heading', 'pageTitle', 'moduleTitle', 'text'],
            'filterableAttributes' => ['module', 'source'],
            'stopWords' => self::STOP_WORDS,
            'localizedAttributes' => [['attributePatterns' => ['*'], 'locales' => ['spa']]],
        ];
    }

    /**
     * Compara lo guardado en el índice con `indexSettings()`. Meilisearch devuelve ordenadas las
     * listas que son conjuntos (`filterableAttributes`, `stopWords`); el orden de
     * `searchableAttributes` sí cuenta. Ante cualquier diferencia, también de forma, responde
     * false y `configure()` manda los settings como siempre.
     *
     * Las stop words se comparan en forma Unicode NFC: Meilisearch 1.33 las devuelve descompuestas
     * («cómo» como `o` + tilde combinante), y comparadas tal cual nunca coincidían con las del
     * código, así que el comando mandaba los settings en cada corrida (visto en producción el
     * 2026-11-12, antes de indexar las guías en video).
     */
    private function storedSettingsMatch(): bool
    {
        $stored = $this->client()->get("/indexes/{$this->uid()}/settings")->throw()->json();
        $wanted = $this->indexSettings();
        $asSet = function (mixed $values): ?array {
            if (! is_array($values)) {
                return null;
            }

            $values = array_map(
                fn (mixed $value): mixed => is_string($value) ? (Normalizer::normalize($value, Normalizer::FORM_C) ?: $value) : $value,
                $values,
            );

            sort($values);

            return $values;
        };

        return ($stored['searchableAttributes'] ?? null) === $wanted['searchableAttributes']
            && $asSet($stored['filterableAttributes'] ?? null) === $asSet($wanted['filterableAttributes'])
            && $asSet($stored['stopWords'] ?? null) === $asSet($wanted['stopWords'])
            && ($stored['localizedAttributes'] ?? null) == $wanted['localizedAttributes'];
    }

    /**
     * Deja el índice igual a `$chunks`: agrega, actualiza por hash y borra lo que ya no viene.
     * Con `$keepPrefix`, los documentos cuyo id empieza así no se tocan aunque no vengan: es para
     * indexar la guía cuando el catálogo de guías en video no se pudo leer, sin borrar las que ya
     * estaban.
     *
     * @param  Collection<int, KnowledgeChunk>  $chunks
     * @return array{added: int, updated: int, deleted: int, unchanged: int}
     */
    public function sync(Collection $chunks, ?string $keepPrefix = null): array
    {
        $existing = $this->existingHashes();

        if ($keepPrefix !== null && $keepPrefix !== '') {
            $existing = $existing->reject(fn (string $hash, string $id): bool => str_starts_with($id, $keepPrefix));
        }

        $incoming = $chunks->keyBy('id');

        $added = $incoming->reject(fn (KnowledgeChunk $chunk): bool => $existing->has($chunk->id));
        $updated = $incoming->filter(fn (KnowledgeChunk $chunk): bool => $existing->has($chunk->id) && $existing[$chunk->id] !== $chunk->hash);
        $deleted = $existing->keys()->reject(fn (string $id): bool => $incoming->has($id))->values();

        foreach ($added->merge($updated)->values()->chunk(self::BATCH_SIZE) as $batch) {
            $task = $this->client()->post("/indexes/{$this->uid()}/documents", $batch->map->toDocument()->values()->all())->throw()->json('taskUid');
            $this->waitTask($task);
        }

        if ($deleted->isNotEmpty()) {
            $task = $this->client()->post("/indexes/{$this->uid()}/documents/delete-batch", $deleted->all())->throw()->json('taskUid');
            $this->waitTask($task);
        }

        return [
            'added' => $added->count(),
            'updated' => $updated->count(),
            'deleted' => $deleted->count(),
            'unchanged' => $incoming->count() - $added->count() - $updated->count(),
        ];
    }

    /**
     * Cuántos documentos del índice quedaron sin vector, según `GET /indexes/{uid}/stats`; null si
     * la versión de Meilisearch no informa `numberOfEmbeddedDocuments`. Un documento sin vector
     * solo aparece por palabras. Meilisearch 1.22.2 deja sin vector cada 13.º documento de cada
     * grupo de 100 que embebe (medido 2026-11-11, conocimiento.md), y reenviarlo no lo repara
     * porque su texto no cambió.
     *
     * @return array{documents: int, without_vectors: ?int}
     */
    public function vectorCoverage(): array
    {
        $stats = $this->client()->get("/indexes/{$this->uid()}/stats")->throw();
        $documents = (int) $stats->json('numberOfDocuments', 0);
        $embedded = $stats->json('numberOfEmbeddedDocuments');

        return [
            'documents' => $documents,
            'without_vectors' => is_int($embedded) ? max(0, $documents - $embedded) : null,
        ];
    }

    /**
     * Páginas de la guía y preguntas del banco; las guías en video salen por `searchVideos()`,
     * para que no le quiten lugar a las páginas (el eval mide páginas).
     */
    public function search(string $query, ?string $module = null, int $limit = 5, ?float $semanticRatio = null): array
    {
        $filter = 'source != "video"';

        if ($module !== null) {
            $filter = 'module = "'.addcslashes($module, '\\"').'" AND '.$filter;
        }

        return $this->hits($query, $filter, $limit, ['id', 'source', 'module', 'page', 'heading', 'anchor', 'text'], $semanticRatio ?? (float) AssistantNaming::config('knowledge.semantic_ratio'))
            ->map(fn (array $hit): array => [
                'id' => (string) $hit['id'],
                'source' => (string) $hit['source'],
                'module' => (string) $hit['module'],
                'page' => (string) $hit['page'],
                'heading' => (string) $hit['heading'],
                'anchor' => $hit['anchor'] ?? null,
                'excerpt' => Str::limit((string) ($hit['text'] ?? ''), self::EXCERPT_LENGTH, ''),
                'score' => (float) ($hit['_rankingScore'] ?? 0.0),
            ])
            ->all();
    }

    /**
     * No pide `text`: quien llama decide por título y pasos, y son hasta `videos.candidates`
     * documentos por búsqueda.
     */
    public function searchVideos(string $query, int $limit = 5): array
    {
        return $this->hits($query, 'source = "video"', $limit, ['guide', 'module', 'heading', 'menu', 'steps', 'duration'], (float) AssistantNaming::config('knowledge.videos.semantic_ratio'))
            ->filter(fn (array $hit): bool => is_string($hit['guide'] ?? null) && $hit['guide'] !== '')
            ->map(fn (array $hit): array => [
                'guide' => $hit['guide'],
                'module' => (string) ($hit['module'] ?? ''),
                'title' => (string) ($hit['heading'] ?? ''),
                'menu' => array_values(array_filter((array) ($hit['menu'] ?? []), 'is_string')),
                'steps' => collect((array) ($hit['steps'] ?? []))
                    ->filter(fn (mixed $step): bool => is_array($step) && is_string($step['id'] ?? null) && $step['id'] !== '')
                    ->map(fn (array $step): array => ['id' => $step['id'], 'title' => (string) ($step['title'] ?? '')])
                    ->values()
                    ->all(),
                'duration' => (int) ($hit['duration'] ?? 0),
                'score' => (float) ($hit['_rankingScore'] ?? 0.0),
            ])
            ->values()
            ->all();
    }

    /**
     * Los resultados salen en el orden de Meilisearch, que mezcla la mitad de palabras y la
     * semántica con sus puntajes ya pesados por la razón. No se reordenan por `_rankingScore`: ese
     * puntaje viene sin pesar y no es comparable entre las dos mitades (uno semántico de e5 ronda
     * 0.9 aunque no tenga que ver; uno de palabras, 0.6 aunque sea exacto). Reordenar bajaba el
     * top-1 (medido 2026-11-12, conocimiento.md).
     *
     * El prefijo de consulta del embebedor va en `q`, que Meilisearch usa para las dos mitades:
     * en la de palabras no cambia los resultados (medido con el eval, conocimiento.md).
     *
     * @param  list<string>  $attributes
     * @return Collection<int, array<string, mixed>>
     *
     * @throws KnowledgeIndexUnavailable
     */
    private function hits(string $query, string $filter, int $limit, array $attributes, float $semanticRatio): Collection
    {
        $payload = [
            'q' => AssistantNaming::config('knowledge.embedder.query_prefix', '').$query,
            'limit' => $limit,
            'hybrid' => [
                'embedder' => AssistantNaming::config('knowledge.embedder.name'),
                'semanticRatio' => $semanticRatio,
            ],
            'matchingStrategy' => AssistantNaming::config('knowledge.matching_strategy'),
            'showRankingScore' => true,
            'attributesToRetrieve' => $attributes,
            'locales' => ['spa'],
            'filter' => $filter,
        ];

        try {
            $hits = $this->client()->post("/indexes/{$this->uid()}/search", $payload)->throw()->json('hits') ?? [];
        } catch (RequestException|ConnectionException $e) {
            throw new KnowledgeIndexUnavailable('El índice de conocimiento no está disponible: '.$e->getMessage(), 0, $e);
        }

        return collect($hits)->filter(fn (mixed $hit): bool => is_array($hit))->values();
    }

    /**
     * @return Collection<string, string>
     */
    private function existingHashes(): Collection
    {
        $hashes = collect();
        $offset = 0;

        do {
            $page = $this->client()->get("/indexes/{$this->uid()}/documents", [
                'fields' => 'id,hash',
                'limit' => self::PAGE_SIZE,
                'offset' => $offset,
            ])->throw();

            foreach ($page->json('results') ?? [] as $document) {
                $hashes[$document['id']] = $document['hash'] ?? '';
            }

            $offset += self::PAGE_SIZE;
        } while ($offset < ($page->json('total') ?? 0));

        return $hashes;
    }

    private function waitTask(int $task): void
    {
        $deadline = microtime(true) + (int) AssistantNaming::config('knowledge.task_timeout');

        do {
            $response = $this->client()->get("/tasks/{$task}");

            if (! $response->successful()) {
                throw new RuntimeException("Tarea {$task} de Meilisearch: HTTP {$response->status()} al consultarla");
            }

            $status = $response->json('status');

            if ($status === 'succeeded') {
                return;
            }

            if (in_array($status, ['failed', 'canceled'], true)) {
                $detail = $response->json('error.message') !== null
                    ? ' ('.$response->json('error.code').': '.$response->json('error.message').')'
                    : '';

                throw new RuntimeException("Tarea {$task} de Meilisearch: {$status}{$detail}");
            }

            usleep(200_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Tarea {$task} de Meilisearch: tiempo de espera agotado");
    }

    /**
     * @return array{source: string, model: string, documentTemplate: string, documentTemplateMaxBytes: int}
     */
    private function embedderSettings(): array
    {
        return [
            'source' => 'huggingFace',
            'model' => (string) AssistantNaming::config('knowledge.embedder.model'),
            'documentTemplate' => AssistantNaming::config('knowledge.embedder.document_prefix', '')."{{doc.moduleTitle}} · {{doc.pageTitle}} · {{doc.heading}}\n{{doc.text|truncatewords: 250}}",
            'documentTemplateMaxBytes' => self::TEMPLATE_MAX_BYTES,
        ];
    }

    private function uid(): string
    {
        return AssistantNaming::config('knowledge.index');
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('scout.meilisearch.key'))
            ->baseUrl(config('scout.meilisearch.host'))
            ->acceptJson()
            ->timeout(30);
    }
}
