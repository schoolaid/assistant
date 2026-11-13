<?php

namespace SchoolAid\Assistant\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Support\AssistantNaming;

/**
 * What an app that already has another "assistant" does in its own provider: it registers its name
 * and binds the contracts. It registers after the package's provider, as a discovered package does.
 */
class HostServiceProvider extends ServiceProvider
{
    public const NAME = 'panel_assistant';

    public function register(): void
    {
        AssistantNaming::use(self::NAME);

        $this->app->singleton(Workspace::class, FakeWorkspace::class);
    }
}
