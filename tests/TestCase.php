<?php

namespace SchoolAid\Assistant\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SchoolAid\Assistant\Providers\AssistantServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AssistantServiceProvider::class];
    }
}
