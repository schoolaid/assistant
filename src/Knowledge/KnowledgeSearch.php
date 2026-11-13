<?php

namespace SchoolAid\Assistant\Knowledge;

interface KnowledgeSearch
{
    /**
     * Páginas de la guía y preguntas del banco más cercanas a la consulta, de más a menos cercana
     * (el orden de Meilisearch; `score` no es comparable entre resultados de palabras y semánticos).
     *
     * @return array<int, array{id: string, source: string, module: string, page: string, heading: string, anchor: ?string, excerpt: string, score: float}>
     *
     * @throws KnowledgeIndexUnavailable
     */
    public function search(string $query, ?string $module = null, int $limit = 5, ?float $semanticRatio = null): array;

    /**
     * Guías en video del Manual del panel más cercanas a la consulta, de más a menos cercana.
     * `menu` son las claves de menú que habilitan la guía: quien llama decide si la persona la
     * puede ver. `steps` trae solo los pasos que se pueden enlazar (`?paso=<id>`).
     *
     * @return array<int, array{guide: string, module: string, title: string, menu: list<string>, steps: list<array{id: string, title: string}>, duration: int, score: float}>
     *
     * @throws KnowledgeIndexUnavailable
     */
    public function searchVideos(string $query, int $limit = 5): array;
}
