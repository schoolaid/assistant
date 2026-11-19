<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SchoolAid\Assistant\Enums\AssistantAudienceEnum;
use SchoolAid\Assistant\Models\AssistantConversation;

/*
| An app that published the first two migrations already has conversations. The third one adds who
| the conversation serves (`audience`, `owner_*`, `impersonation_id`) and lets `user_id` be empty,
| without touching the rows that are there.
*/

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
