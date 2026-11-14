<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SchoolAid\Assistant\Jobs\RunAssistantStep;
use SchoolAid\Assistant\Models\AssistantConfirmation;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Models\AssistantUsage;
use SchoolAid\Assistant\Models\Scopes\WorkspaceScope;
use SchoolAid\Assistant\Support\AssistantNaming;

const WORKSPACE_TABLES = ['conversations', 'turns', 'messages', 'confirmations', 'exports'];
const PLATFORM_TABLES = ['usage', 'user_usage', 'api_keys', 'limits', 'tenant_settings'];

/**
 * A confirmation with its message, turn and conversation, all created inside one workspace.
 */
function confirmationIn(string $workspaceId): AssistantConfirmation
{
    return test()->workspace()->run($workspaceId, function (): AssistantConfirmation {
        $conversation = AssistantConversation::factory()->create();
        $turn = AssistantTurn::factory()->create(['conversation_id' => $conversation->id]);
        $message = AssistantMessage::factory()->create(['conversation_id' => $conversation->id, 'turn_id' => $turn->id]);

        return AssistantConfirmation::factory()->create([
            'conversation_id' => $conversation->id,
            'turn_id' => $turn->id,
            'message_id' => $message->id,
        ]);
    });
}

it('merges the engine defaults under the name the app registered', function () {
    expect(AssistantNaming::name())->toBe('panel_assistant')
        ->and(config('panel_assistant.model'))->toBeString()
        ->and(config('panel_assistant.exports.page_size'))->toBe(50)
        ->and(config('panel_assistant.workspace_column'))->toBe('school_id')
        ->and(config('assistant'))->toBeNull();
});

it('creates every table with the name of the app and none with the default one', function () {
    foreach ([...WORKSPACE_TABLES, ...PLATFORM_TABLES] as $table) {
        expect(Schema::hasTable("panel_assistant_{$table}"))->toBeTrue($table)
            ->and(Schema::hasTable("assistant_{$table}"))->toBeFalse($table);
    }
});

it('adds the workspace column to the workspace tables only', function () {
    foreach (WORKSPACE_TABLES as $table) {
        expect(Schema::hasColumn("panel_assistant_{$table}", 'school_id'))->toBeTrue($table);
    }

    foreach (PLATFORM_TABLES as $table) {
        expect(Schema::hasColumn("panel_assistant_{$table}", 'school_id'))->toBeFalse($table);
    }
});

it('stamps each row with the workspace where it was created', function () {
    $confirmation = confirmationIn('1');

    foreach (['conversations', 'turns', 'messages', 'confirmations'] as $table) {
        expect(DB::table("panel_assistant_{$table}")->pluck('school_id')->all())->toBe(['1'], $table);
    }

    expect($confirmation->getAttribute('school_id'))->toBe('1');
});

it('reads only the rows of the current workspace', function (string $model) {
    $mine = confirmationIn('1');
    $theirs = confirmationIn('2');
    $key = fn (AssistantConfirmation $confirmation): int => match ($model) {
        AssistantConversation::class => $confirmation->conversation_id,
        AssistantTurn::class => $confirmation->turn_id,
        AssistantMessage::class => $confirmation->message_id,
        AssistantConfirmation::class => $confirmation->id,
    };

    $this->workspace()->current = '1';

    expect($model::query()->count())->toBe(1)
        ->and($model::query()->find($key($mine)))->not->toBeNull()
        ->and($model::query()->find($key($theirs)))->toBeNull()
        ->and($model::query()->withoutGlobalScope(WorkspaceScope::class)->count())->toBe(2);

    $this->workspace()->current = null;

    expect($model::query()->count())->toBe(0)
        ->and($model::query()->find($key($mine)))->toBeNull();
})->with([
    [AssistantConversation::class],
    [AssistantTurn::class],
    [AssistantMessage::class],
    [AssistantConfirmation::class],
]);

it('does not reach another workspace through a relation', function () {
    $mine = confirmationIn('1');
    $theirs = confirmationIn('2');

    $this->workspace()->current = '1';

    expect($mine->conversation->turns()->count())->toBe(1)
        ->and(AssistantConversation::query()->whereHas('turns')->count())->toBe(1)
        ->and(AssistantTurn::query()->where('conversation_id', $theirs->conversation_id)->exists())->toBeFalse();
});

it('refuses to create a row outside a workspace', function () {
    expect(fn () => AssistantConversation::factory()->create())->toThrow(Illuminate\Database\QueryException::class);
});

it('runs a queued step back in the workspace where it was dispatched', function () {
    $turnId = confirmationIn('1')->turn_id;

    $job = $this->workspace()->run('1', fn (): RunAssistantStep => unserialize(serialize(new RunAssistantStep($turnId))));
    $found = [];

    expect($this->workspace()->id())->toBeNull();

    $job->middleware()[0]->handle($job, function () use (&$found, $turnId): void {
        $found[] = AssistantTurn::query()->find($turnId)?->id;
    });

    expect($found)->toBe([$turnId])
        ->and($this->workspace()->id())->toBeNull();
});

it('keeps the platform tables outside the workspace scope, keyed by tenant_id', function () {
    AssistantUsage::factory()->create(['tenant_id' => '1']);
    AssistantUsage::factory()->create(['tenant_id' => '2']);

    expect(AssistantUsage::query()->count())->toBe(2)
        ->and(DB::table('panel_assistant_usage')->count())->toBe(2);
});

it('can run the migrations again without touching what is there', function () {
    confirmationIn('1');

    foreach ($this->migrations() as $migration) {
        $migration->up();
    }

    expect(DB::table('panel_assistant_confirmations')->count())->toBe(1);
});

it('makes the workspace column numeric when the app says its workspace ids are', function () {
    foreach (array_reverse($this->migrations()) as $migration) {
        $migration->down();
    }

    config(['panel_assistant.workspace_column_type' => 'integer']);

    foreach ($this->migrations() as $migration) {
        $migration->up();
    }

    $confirmation = confirmationIn('2');
    $this->workspace()->current = '2';

    expect(Schema::getColumnType('panel_assistant_turns', 'school_id'))->toBe('integer')
        ->and(DB::table('panel_assistant_turns')->value('school_id'))->toBe(2)
        ->and(AssistantTurn::query()->find($confirmation->turn_id))->not->toBeNull();

    $this->workspace()->current = '1';

    expect(AssistantTurn::query()->find($confirmation->turn_id))->toBeNull();
});
