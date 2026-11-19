<?php

namespace SchoolAid\Assistant\Services\Entities;

use SchoolAid\Assistant\Contracts\EntitySearch;
use SchoolAid\Assistant\Models\AssistantConversation;

/**
 * Lo que ofrece el selector `@` del panel: con texto, lo que encontró la app ({@see EntitySearch}); sin texto y con
 * conversación, sus registros más recientes. Todo pasa por {@see AssistantMentions::labelled()}, así que solo sale lo
 * que la persona puede adjuntar.
 */
final class AssistantEntitySearcher
{
    /**
     * Caracteres mínimos para preguntarle a la app.
     */
    public const MIN_QUERY = 2;

    /**
     * Resultados por grupo que se le piden a la app.
     */
    public const PER_GROUP = 5;

    /**
     * Registros de la conversación que se ofrecen sin texto.
     */
    public const RECENT = 8;

    public function __construct(
        private readonly EntitySearch $search,
        private readonly AssistantEntityExtractor $extractor,
        private readonly AssistantMentions $mentions,
    ) {
    }

    /**
     * Con al menos {@see self::MIN_QUERY} caracteres (sin los espacios de los lados), lo que encontró la app, en su
     * orden, sin repetidos y sin lo que no se puede ver. Si no, los registros de `$conversation` del más nuevo al más
     * viejo ({@see AssistantEntityExtractor::recentFromConversation()}), sin los que tienen fecha (una clase de un
     * horario: adjuntarla perdería el día) y hasta {@see self::RECENT} de los que se pueden ver, con `group` null. Se
     * etiquetan a lo más los {@see AssistantEntityExtractor::MAX_ENTITIES} más recientes, en una sola llamada. Sin
     * texto ni conversación, nada.
     *
     * @return list<array{ref: AssistantEntityRef, group: string|null, entity: array{type: string, resource: string, id: int, date: string|null, label: string, detail: string|null, created: bool}}>
     */
    public function search(string $query, ?AssistantConversation $conversation): array
    {
        $query = trim($query);

        if (mb_strlen($query) >= self::MIN_QUERY) {
            return $this->found($query);
        }

        if ($conversation !== null) {
            return $this->recent($conversation);
        }

        return [];
    }

    /**
     * @return list<array{ref: AssistantEntityRef, group: string|null, entity: array<string, mixed>}>
     */
    private function found(string $query): array
    {
        $unique = [];

        foreach ($this->search->search($query, self::PER_GROUP) as $result) {
            $unique[$result['ref']->identity()] ??= $result;
        }

        return $this->visible(array_column($unique, 'ref'), array_column($unique, 'group'));
    }

    /**
     * @return list<array{ref: AssistantEntityRef, group: string|null, entity: array<string, mixed>}>
     */
    private function recent(AssistantConversation $conversation): array
    {
        $refs = array_slice(array_values(array_filter(
            $this->extractor->recentFromConversation($conversation),
            fn (AssistantEntityRef $ref): bool => $ref->date === null,
        )), 0, AssistantEntityExtractor::MAX_ENTITIES);

        return array_slice($this->visible($refs, array_fill(0, count($refs), null)), 0, self::RECENT);
    }

    /**
     * Cada ref con su grupo y su entidad etiquetada, sin los que no se pueden ver.
     *
     * @param  list<AssistantEntityRef>  $refs
     * @param  list<string|null>  $groups  alineada con `$refs`
     * @return list<array{ref: AssistantEntityRef, group: string|null, entity: array<string, mixed>}>
     */
    private function visible(array $refs, array $groups): array
    {
        $results = [];

        foreach ($this->mentions->labelled($refs) as $index => $entity) {
            if ($entity !== null) {
                $results[] = ['ref' => $refs[$index], 'group' => $groups[$index], 'entity' => $entity];
            }
        }

        return $results;
    }
}
