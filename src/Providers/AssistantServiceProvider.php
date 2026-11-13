<?php

namespace SchoolAid\Assistant\Providers;

use Illuminate\Support\ServiceProvider;
use SchoolAid\Assistant\Knowledge\KnowledgeSearch;
use SchoolAid\Assistant\Knowledge\MeilisearchKnowledgeIndex;
use SchoolAid\Assistant\Services\Tools\AssistantToolBridge;
use SchoolAid\Assistant\Services\Tools\ToolBridge;
use SchoolAid\Assistant\Support\AssistantActionEventMark;

/**
 * Binds what the engine provides by itself. Everything under `SchoolAid\Assistant\Contracts` is the
 * app's to bind, in its own provider: the engine does not run until every contract has an
 * implementation.
 */
class AssistantServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        KnowledgeSearch::class => MeilisearchKnowledgeIndex::class,
        ToolBridge::class => AssistantToolBridge::class,
    ];

    /**
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        AssistantActionEventMark::class => AssistantActionEventMark::class,
    ];
}
