<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use SchoolAid\Assistant\Contracts\EntitySearch;
use SchoolAid\Assistant\Http\AssistantRoutes;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Services\Entities\AssistantEntityRef;
use SchoolAid\Assistant\Tests\Fixtures\FakeEntitySearch;
use SchoolAid\Assistant\Tests\Fixtures\ScriptedTurn;

/*
| The @ picker of the panel: what the app found for the text, or the recent records of a
| conversation, always labelled by the app (what it cannot label is left out) and never across
| workspaces.
*/

uses(ScriptedTurn::class);

const SEARCH_USER_ID = 7;

beforeEach(function () {
    $this->bindTurnDoubles();

    Route::prefix('api')->group(fn () => AssistantRoutes::panel('panel-assistant', 'api.panel-assistant.'));
    Route::getRoutes()->refreshNameLookups();

    // The app's policy: here every conversation the person can reach is theirs.
    Gate::define('view', fn ($user, AssistantConversation $conversation): bool => true);

    $this->actingAs(new GenericUser(['id' => SEARCH_USER_ID]));
});

function searchIn(string $workspaceId, array $query): TestResponse
{
    return test()->workspace()->run($workspaceId, fn (): TestResponse => test()->getJson(
        '/api/panel-assistant/entities/search?'.http_build_query($query),
    ));
}

/**
 * A conversation of workspace '7' whose person attached user 31 to a message.
 */
function conversationWithAttachedUser(): AssistantConversation
{
    return test()->workspace()->run('7', function (): AssistantConversation {
        $conversation = AssistantConversation::factory()->create(['user_id' => SEARCH_USER_ID]);
        AssistantMessage::factory()->create([
            'conversation_id' => $conversation->id, 'role' => 'user', 'kind' => 'text',
            'content' => [['type' => 'text', 'text' => '¿Qué roles tiene?']],
            'payload' => ['mentions' => [['type' => 'user', 'id' => 31, 'model_key' => null]]],
        ]);

        return $conversation;
    });
}

it('offers the recent records of a conversation only inside its workspace', function () {
    $conversation = conversationWithAttachedUser();

    searchIn('7', ['conversation' => $conversation->id])
        ->assertOk()
        ->assertJsonPath('data.0.ref', ['type' => 'user', 'id' => 31, 'model_key' => null])
        ->assertJsonPath('data.0.group', null)
        ->assertJsonPath('data.0.entity.label', 'user 31')
        ->assertJsonCount(1, 'data');

    searchIn('8', ['conversation' => $conversation->id])->assertNotFound();
});

it('asks the app for what the person typed and drops what the app cannot label', function () {
    $search = app(EntitySearch::class);
    expect($search)->toBeInstanceOf(FakeEntitySearch::class);
    $search->results = [
        ['ref' => new AssistantEntityRef('user', 31), 'group' => 'Users'],
        ['ref' => new AssistantEntityRef('user', 5000), 'group' => 'Users'],
    ];

    searchIn('7', ['q' => 'an'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ref.id', 31)
        ->assertJsonPath('data.0.group', 'Users');

    expect($search->queries)->toBe([['an', 5]]);
});

it('does not ask the app for a single character', function () {
    searchIn('7', ['q' => 'a'])->assertOk()->assertJsonCount(0, 'data');

    expect(app(EntitySearch::class)->queries)->toBe([]);
});
