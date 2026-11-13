<?php

namespace SchoolAid\Assistant\Knowledge;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Catálogo público de las guías en video del Manual del panel (`guides/catalog.json`, lo publica
 * `pnpm guides publish` del front; forma en docs/modules/guides/dev/index.md). Es contenido de
 * demo, sin datos de clientes. Con `assistant.knowledge.videos.catalog_url` vacío no hay guías.
 */
class VideoGuideCatalog
{
    private const VERSION = 1;

    /**
     * Forma de un id de guía (el mismo patrón que valida `guide_id` en StoreGuideSearchLogRequest):
     * acaba como llave primaria en Meilisearch y como tramo de la URL `/manual/<id>`.
     */
    private const GUIDE_ID = '/^[a-z0-9][a-z0-9-]*$/';

    /**
     * Forma de un id de paso: va en `?paso=<id>` de la misma URL.
     */
    private const STEP_ID = '/^[a-z0-9][a-z0-9_-]*$/';

    /**
     * Las guías del catálogo, cada una con el nombre visible de su módulo. Una guía con un id que
     * no cumple {@see self::GUIDE_ID} se salta; a un paso con un id que no cumple
     * {@see self::STEP_ID} se le quita el id (su texto se indexa, pero no se puede enlazar).
     *
     * @return list<array{id: string, module: string, moduleTitle: string, title: string, summary: string, menu: list<string>, intents: list<string>, durationSeconds: int, steps: list<array{id: ?string, title: string, text: string}>}>
     *
     * @throws RuntimeException si el catálogo no se puede leer, trae otra versión o viene sin guías
     */
    public function guides(): array
    {
        $url = (string) config('assistant.knowledge.videos.catalog_url');

        if ($url === '') {
            return [];
        }

        $response = Http::acceptJson()->timeout(20)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Catálogo de guías en video: HTTP {$response->status()} al leer {$url}");
        }

        if ($response->json('version') !== self::VERSION || ! is_array($response->json('guides'))) {
            throw new RuntimeException("Catálogo de guías en video: {$url} no trae la versión ".self::VERSION.' del catálogo');
        }

        $modules = (array) $response->json('modules', []);

        $guides = collect($response->json('guides'))
            ->filter(fn (mixed $guide): bool => is_array($guide)
                && is_string($guide['id'] ?? null)
                && preg_match(self::GUIDE_ID, $guide['id']) === 1
                && filled($guide['title'] ?? null))
            ->map(fn (array $guide): array => [
                'id' => $guide['id'],
                'module' => (string) ($guide['module'] ?? ''),
                'moduleTitle' => (string) ($modules[$guide['module'] ?? ''] ?? $guide['module'] ?? ''),
                'title' => (string) $guide['title'],
                'summary' => (string) ($guide['summary'] ?? ''),
                'menu' => array_values(array_map('strval', (array) ($guide['menu'] ?? []))),
                'intents' => array_values(array_map('strval', (array) ($guide['intents'] ?? []))),
                'durationSeconds' => (int) round((float) ($guide['durationSeconds'] ?? 0)),
                'steps' => collect((array) ($guide['steps'] ?? []))
                    ->filter(fn (mixed $step): bool => is_array($step))
                    ->map(fn (array $step): array => [
                        'id' => is_string($step['id'] ?? null) && preg_match(self::STEP_ID, $step['id']) === 1 ? $step['id'] : null,
                        'title' => (string) ($step['title'] ?? ''),
                        'text' => (string) ($step['text'] ?? ''),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        if ($guides === []) {
            throw new RuntimeException("Catálogo de guías en video: {$url} viene sin guías");
        }

        return $guides;
    }
}
