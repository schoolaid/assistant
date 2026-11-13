<?php

namespace SchoolAid\Assistant\Tests;

use Illuminate\Database\Migrations\Migration;
use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Providers\AssistantServiceProvider;
use SchoolAid\Assistant\Support\AssistantNaming;
use SchoolAid\Assistant\Tests\Fixtures\FakeWorkspace;
use SchoolAid\Assistant\Tests\Fixtures\HostServiceProvider;

/**
 * An app with every workspace in one database, another name for the assistant and a workspace
 * column: the package's migrations run on an in-memory SQLite database.
 */
abstract class SingleDatabaseTestCase extends TestCase
{
    public const WORKSPACE_COLUMN = 'school_id';

    protected function getPackageProviders($app): array
    {
        return [AssistantServiceProvider::class, HostServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set(HostServiceProvider::NAME.'.workspace_column', self::WORKSPACE_COLUMN);
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->migrations() as $migration) {
            $migration->up();
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        AssistantNaming::use(AssistantNaming::DEFAULT);
    }

    protected function workspace(): FakeWorkspace
    {
        return $this->app->make(Workspace::class);
    }

    /**
     * @return list<Migration>
     */
    protected function migrations(): array
    {
        $files = glob(dirname(__DIR__).'/database/migrations/*.php') ?: [];
        sort($files);

        return array_map(fn (string $file): Migration => require $file, $files);
    }
}
