<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use SchoolAid\Assistant\Enums\AssistantAudienceEnum;
use SchoolAid\Assistant\Http\AssistantRoutes;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Services\AssistantSchemaCheck;
use SchoolAid\Assistant\Tests\Fixtures\FakeUser;
use SchoolAid\Assistant\Tests\Fixtures\ScriptedTurn;

/*
| An app that published the first two migrations already has conversations. The third one adds who
| the conversation serves (`audience`, `owner_*`, `impersonation_id`) and lets `user_id` be empty,
| without touching the rows that are there.
*/

uses(ScriptedTurn::class);

const AUDIENCE_MIGRATION = '2026_11_19_000000_add_audience_to_assistant_conversations.php';

/**
 * The migrations of the package, the audience one left out or alone.
 *
 * @return list<Migration>
 */
function packageMigrations(bool $audience): array
{
    $files = glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];
    sort($files);

    return array_values(array_map(
        fn (string $file): Migration => require $file,
        array_filter($files, fn (string $file): bool => (basename($file) === AUDIENCE_MIGRATION) === $audience),
    ));
}

/**
 * The workspace tables as an app that ran only the first two migrations has them, with one
 * conversation in workspace '1'.
 */
function tablesBeforeAudience(): void
{
    foreach (['exports', 'confirmations', 'messages', 'turns', 'conversations'] as $table) {
        Schema::dropIfExists("panel_assistant_{$table}");
    }

    foreach (packageMigrations(audience: false) as $migration) {
        $migration->up();
    }

    DB::table('panel_assistant_conversations')->insert([
        'school_id' => '1', 'user_id' => 7, 'title' => 'Ruta 12', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function runAudienceMigration(): void
{
    foreach (packageMigrations(audience: true) as $migration) {
        $migration->up();
    }
}

function rollbackAudienceMigration(): void
{
    foreach (packageMigrations(audience: true) as $migration) {
        $migration->down();
    }
}

function conversationsColumn(string $name): ?array
{
    return collect(Schema::getColumns('panel_assistant_conversations'))->firstWhere('name', $name);
}

it('adds the audience columns to the conversations of an app that already migrated', function () {
    tablesBeforeAudience();
    expect(Schema::hasColumn('panel_assistant_conversations', 'audience'))->toBeFalse();

    runAudienceMigration();

    foreach (['audience', 'owner_type', 'owner_id', 'impersonation_id'] as $column) {
        expect(Schema::hasColumn('panel_assistant_conversations', $column))->toBeTrue($column);
    }
});

it('leaves the conversations that were there as staff conversations', function () {
    tablesBeforeAudience();

    runAudienceMigration();

    expect(DB::table('panel_assistant_conversations')->count())->toBe(1)
        ->and(DB::table('panel_assistant_conversations')->value('audience'))->toBe('staff')
        ->and(DB::table('panel_assistant_conversations')->value('title'))->toBe('Ruta 12');
});

it('accepts a conversation without a panel user', function () {
    $conversation = $this->workspace()->run('1', fn (): AssistantConversation => AssistantConversation::factory()->create([
        'user_id' => null,
        'audience' => AssistantAudienceEnum::Staff,
    ]));

    expect(DB::table('panel_assistant_conversations')->where('id', $conversation->id)->value('user_id'))->toBeNull();
});

it('runs again without touching what is there', function () {
    tablesBeforeAudience();
    runAudienceMigration();

    runAudienceMigration();

    expect(DB::table('panel_assistant_conversations')->count())->toBe(1)
        ->and(DB::table('panel_assistant_conversations')->value('audience'))->toBe('staff');
});

it('finishes a run that stopped after adding the owner type', function () {
    tablesBeforeAudience();
    Schema::table('panel_assistant_conversations', fn (Blueprint $table) => $table->string('owner_type')->nullable());

    runAudienceMigration();

    expect(Schema::hasColumn('panel_assistant_conversations', 'owner_id'))->toBeTrue()
        ->and(Schema::hasIndex('panel_assistant_conversations', ['owner_type', 'owner_id']))->toBeTrue()
        ->and(Schema::hasColumn('panel_assistant_conversations', 'impersonation_id'))->toBeTrue()
        ->and(conversationsColumn('user_id')['nullable'])->toBeTrue();
});

it('changes nothing in the schema when it runs again', function () {
    tablesBeforeAudience();
    runAudienceMigration();

    DB::flushQueryLog();
    DB::enableQueryLog();
    runAudienceMigration();

    $changes = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $query): bool => preg_match('/^\s*(alter|create|drop|insert)\b/i', $query) === 1);

    expect($changes->values()->all())->toBe([]);
});

it('undoes itself when every conversation has a panel user', function () {
    tablesBeforeAudience();
    runAudienceMigration();

    rollbackAudienceMigration();

    foreach (['audience', 'owner_type', 'owner_id', 'impersonation_id'] as $column) {
        expect(Schema::hasColumn('panel_assistant_conversations', $column))->toBeFalse($column);
    }

    expect(conversationsColumn('user_id')['nullable'])->toBeFalse()
        ->and(DB::table('panel_assistant_conversations')->value('title'))->toBe('Ruta 12');
});

it('refuses to undo while a conversation has no panel user, before dropping any column', function () {
    tablesBeforeAudience();
    runAudienceMigration();
    DB::table('panel_assistant_conversations')->insert([
        'school_id' => '1', 'user_id' => null, 'audience' => 'customer', 'title' => 'Sin usuario',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        expect(fn () => rollbackAudienceMigration())->toThrow(QueryException::class);
    } finally {
        // SQLite rebuilds the table to change a column and leaves its copy behind when that fails.
        Schema::dropIfExists('__temp__panel_assistant_conversations');
    }

    foreach (['audience', 'owner_type', 'owner_id', 'impersonation_id'] as $column) {
        expect(Schema::hasColumn('panel_assistant_conversations', $column))->toBeTrue($column);
    }
});

it('serves the chat on the schema before the audience columns, and waits for them in the background', function () {
    tablesBeforeAudience();
    Queue::fake();
    $this->bindTurnDoubles();
    FakeUser::createTable();
    Route::middleware(SubstituteBindings::class)->prefix('api')
        ->group(fn () => AssistantRoutes::panel('panel-assistant', 'api.panel-assistant.'));
    Route::getRoutes()->refreshNameLookups();
    Gate::before(fn (): bool => true);
    $person = FakeUser::query()->create(['name' => 'Ana']);
    $this->actingAs($person);
    $conversationId = DB::table('panel_assistant_conversations')->insertGetId([
        'school_id' => '1', 'user_id' => $person->getKey(), 'title' => 'Buses', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->workspace()->run('1', fn () => $this->postJson("/api/panel-assistant/conversations/{$conversationId}/messages", ['content' => '¿Qué buses salen hoy?'])
        ->assertAccepted());
    $this->workspace()->run('1', fn () => $this->getJson("/api/panel-assistant/conversations/{$conversationId}/messages")
        ->assertOk()
        ->assertJsonPath('data.0.text', '¿Qué buses salen hoy?'));

    expect(app(AssistantSchemaCheck::class)->tenantHasTables())->toBeFalse();

    runAudienceMigration();

    expect(app(AssistantSchemaCheck::class)->tenantHasTables())->toBeTrue();
});
