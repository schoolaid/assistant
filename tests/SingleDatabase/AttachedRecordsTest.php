<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use SchoolAid\Assistant\Http\AssistantRoutes;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Tests\Fixtures\FakeEntityLabels;
use SchoolAid\Assistant\Tests\Fixtures\FakeUser;
use SchoolAid\Assistant\Tests\Fixtures\ScriptedTurn;

/*
| Records the person attaches to a message (`mentions`): labelled again by the app before the turn
| opens, stored with the message in its workspace, and listed with the history only there.
*/

uses(ScriptedTurn::class);

beforeEach(function () {
    Queue::fake();
    $this->bindTurnDoubles();
    FakeUser::createTable();

    Route::middleware(SubstituteBindings::class)->prefix('api')
        ->group(fn () => AssistantRoutes::panel('panel-assistant', 'api.panel-assistant.'));
    Route::getRoutes()->refreshNameLookups();

    // The app's policy: every conversation the person can reach is theirs.
    Gate::before(fn (): bool => true);

    $this->person = FakeUser::query()->create(['name' => 'Ana']);
    $this->actingAs($this->person);
    $this->conversation = $this->workspace()->run('7', fn (): AssistantConversation => AssistantConversation::factory()->create(['user_id' => $this->person->getKey()]));
});

function sendIn(string $workspaceId, int $conversationId, array $body): TestResponse
{
    return test()->workspace()->run($workspaceId, fn (): TestResponse => test()->postJson(
        "/api/panel-assistant/conversations/{$conversationId}/messages",
        $body,
    ));
}

function historyIn(string $workspaceId, int $conversationId): TestResponse
{
    return test()->workspace()->run($workspaceId, fn (): TestResponse => test()->getJson(
        "/api/panel-assistant/conversations/{$conversationId}/messages",
    ));
}

it('stores the attached records with the message and lists them with the history', function () {
    sendIn('7', $this->conversation->id, ['content' => '¿Qué roles tiene?', 'mentions' => [['type' => 'user', 'id' => 31]]])
        ->assertAccepted();

    $message = $this->workspace()->run('7', fn (): AssistantMessage => AssistantMessage::query()
        ->where('conversation_id', $this->conversation->id)->where('role', 'user')->sole());

    expect($message->payload['mentions'])->toBe([['type' => 'user', 'id' => 31, 'model_key' => null]])
        ->and($message->getAttribute('school_id'))->toBe('7');

    historyIn('7', $this->conversation->id)
        ->assertOk()
        ->assertJsonPath('data.0.entities.0.label', 'user 31');
});

it('refuses a record the app cannot label, without opening a turn', function () {
    sendIn('7', $this->conversation->id, ['content' => 'Hola', 'mentions' => [['type' => 'user', 'id' => FakeEntityLabels::VISIBLE_BELOW + 1]]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'assistant_mention_not_available');

    expect($this->workspace()->run('7', fn (): int => AssistantMessage::query()->where('conversation_id', $this->conversation->id)->count()))->toBe(0);
});

it('neither sends to nor lists a conversation of another workspace', function () {
    sendIn('7', $this->conversation->id, ['content' => 'Hola', 'mentions' => [['type' => 'user', 'id' => 31]]])->assertAccepted();

    sendIn('8', $this->conversation->id, ['content' => 'Hola', 'mentions' => [['type' => 'user', 'id' => 31]]])->assertNotFound();
    historyIn('8', $this->conversation->id)->assertNotFound();
});

it('names the person the way the engine compares them when confirming', function () {
    $snapshot = app(SchoolAid\Assistant\Contracts\PanelScope::class)->capture($this->person);

    expect($snapshot->principalKey())->toBe(SchoolAid\Assistant\Support\PrincipalKey::of($this->person));
});
