<?php

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SchoolAid\Assistant\Http\AssistantRoutes;
use SchoolAid\Assistant\Providers\AssistantServiceProvider;
use SchoolAid\Assistant\Services\Anthropic\AnthropicSettings;
use SchoolAid\Assistant\Support\AssistantNaming;

it('publishes config, migrations and translations under the name of the app', function () {
    $config = ServiceProvider::pathsToPublish(AssistantServiceProvider::class, 'assistant-config');
    $migrations = ServiceProvider::pathsToPublish(AssistantServiceProvider::class, 'assistant-migrations');
    $translations = ServiceProvider::pathsToPublish(AssistantServiceProvider::class, 'assistant-lang');

    expect(array_values($config))->toBe([config_path('panel_assistant.php')])
        ->and(array_values($migrations))->toBe([database_path('migrations')])
        ->and(array_map('realpath', array_keys($translations)))->each->toBeFile()
        ->and(array_values($translations))->toContain(lang_path('es/panel_assistant.php'), lang_path('en/panel_assistant.php'));
});

it('translates with the published file of the app', function () {
    $lines = require dirname(__DIR__, 2).'/resources/lang/es/assistant.php';

    app('translator')->addLines(
        collect(Illuminate\Support\Arr::dot($lines))->filter(fn ($line) => is_string($line))
            ->mapWithKeys(fn (string $line, string $key) => ["panel_assistant.{$key}" => $line])->all(),
        'es',
    );
    app()->setLocale('es');

    expect(AssistantNaming::trans('validation.content_required'))->not->toStartWith('panel_assistant.')
        ->and(AssistantNaming::trans('step_fallback', ['tool' => 'x']))->not->toStartWith('panel_assistant.');
});

it('mounts the panel routes where the app says, throttled under its name', function () {
    Route::prefix('api')->group(fn () => AssistantRoutes::panel('panel-assistant', 'api.panel-assistant.'));
    Route::getRoutes()->refreshNameLookups();

    $send = Route::getRoutes()->getByName('api.panel-assistant.conversations.messages.store');

    expect($send->uri())->toBe('api/panel-assistant/conversations/{conversation}/messages')
        ->and($send->middleware())->toContain('throttle:panel_assistant')
        ->and(RateLimiter::limiter('panel_assistant'))->not->toBeNull()
        ->and(RateLimiter::limiter('assistant'))->toBeNull();
});

it('reads the Anthropic connection from its own config', function () {
    config(['panel_assistant.anthropic.api_key' => 'key-of-the-app']);

    expect(AnthropicSettings::apiKey())->toBe('key-of-the-app')
        ->and(AnthropicSettings::url())->toBe('https://api.anthropic.com/v1')
        ->and(AnthropicSettings::version())->toBe('2023-06-01');
});
