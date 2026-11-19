<?php

namespace SchoolAid\Assistant\Providers;

use Illuminate\Support\ServiceProvider;
use SchoolAid\Assistant\Console\AssistantFeedbackCommand;
use SchoolAid\Assistant\Console\EvaluateKnowledgeCommand;
use SchoolAid\Assistant\Console\IndexKnowledgeCommand;
use SchoolAid\Assistant\Console\PruneAssistantDataCommand;
use SchoolAid\Assistant\Console\ResumeStaleTurnsCommand;
use SchoolAid\Assistant\Http\AssistantRoutes;
use SchoolAid\Assistant\Knowledge\KnowledgeSearch;
use SchoolAid\Assistant\Knowledge\MeilisearchKnowledgeIndex;
use SchoolAid\Assistant\Services\Audience\AudienceContracts;
use SchoolAid\Assistant\Services\Tools\AssistantToolBridge;
use SchoolAid\Assistant\Services\Tools\ToolBridge;
use SchoolAid\Assistant\Support\AssistantActionEventMark;
use SchoolAid\Assistant\Support\AssistantNaming;

/**
 * Binds what the engine provides by itself. Everything under `SchoolAid\Assistant\Contracts` is the
 * app's to bind, in its own provider: the engine does not run until every contract has an
 * implementation.
 *
 * It also registers the engine's console commands (`assistant:resume-stale-turns`,
 * `assistant:prune`…); the app schedules them.
 *
 * The app may give the assistant another name with AssistantNaming::use() in the `register()` of
 * its provider. That runs after this provider registers, so the config defaults are merged when
 * the app starts booting, under the name the app chose.
 */
class AssistantServiceProvider extends ServiceProvider
{
    private const CONFIG = __DIR__.'/../../config/assistant.php';

    private const MIGRATIONS = __DIR__.'/../../database/migrations';

    private const TRANSLATIONS = __DIR__.'/../../resources/lang';

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
        AudienceContracts::class => AudienceContracts::class,
    ];

    public function register(): void
    {
        $this->app->booting(function (): void {
            $this->mergeConfigFrom(self::CONFIG, AssistantNaming::name());
        });
    }

    public function boot(): void
    {
        AssistantRoutes::rateLimiter();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            AssistantFeedbackCommand::class,
            EvaluateKnowledgeCommand::class,
            IndexKnowledgeCommand::class,
            PruneAssistantDataCommand::class,
            ResumeStaleTurnsCommand::class,
        ]);

        $this->publishes([self::CONFIG => config_path(AssistantNaming::name().'.php')], 'assistant-config');
        $this->publishesMigrations([self::MIGRATIONS => database_path('migrations')], 'assistant-migrations');
        $this->publishes($this->translations(), 'assistant-lang');
    }

    /**
     * The translation file of each locale, published under the name of the assistant in the app.
     *
     * @return array<string, string>
     */
    private function translations(): array
    {
        $files = [];

        foreach (glob(self::TRANSLATIONS.'/*/assistant.php') ?: [] as $file) {
            $files[$file] = lang_path(basename(dirname($file)).'/'.AssistantNaming::name().'.php');
        }

        return $files;
    }
}
