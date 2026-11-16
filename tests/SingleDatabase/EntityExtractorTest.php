<?php

use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Services\Entities\AssistantEntityExtractor;
use SchoolAid\Assistant\Services\Entities\AssistantEntityRef;
use SchoolAid\Assistant\Support\AssistantNaming;

/*
| The records of a conversation, from the app's `entities` map. `output.<key>` reads what a tool
| answered in the chat (JSON); a path with `*` gives a record only when it finds exactly one value,
| so a search that found the record counts and a list of several does not fill the panel.
*/

/**
 * One answered tool call per entry: [tool, input, output text, is_error].
 *
 * @param  list<array{0: string, 1: array<string, mixed>, 2: string, 3?: bool}>  $calls
 * @return list<string>
 */
function extractedFrom(array $calls): array
{
    return test()->workspace()->run('7', function () use ($calls): array {
        $conversation = AssistantConversation::factory()->create();
        $turn = AssistantTurn::factory()->create(['conversation_id' => $conversation->id]);

        foreach ($calls as $index => [$tool, $input, $output]) {
            $id = "toolu_{$index}";
            AssistantMessage::factory()->create([
                'conversation_id' => $conversation->id, 'turn_id' => $turn->id, 'role' => 'assistant', 'kind' => 'text',
                'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => $input]],
            ]);
            AssistantMessage::factory()->create([
                'conversation_id' => $conversation->id, 'turn_id' => $turn->id, 'role' => 'user', 'kind' => 'internal',
                'content' => [['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $output, 'is_error' => $calls[$index][3] ?? false]],
            ]);
        }

        return array_map(
            fn (AssistantEntityRef $ref): string => "{$ref->type}:{$ref->modelKey}:{$ref->id}",
            app(AssistantEntityExtractor::class)->fromConversation($conversation),
        );
    });
}

it('takes a record from a list only when it found exactly one, and never from a failed call', function () {
    config([AssistantNaming::name().'.entities' => [
        'list-users' => [['type' => 'user', 'id' => 'output.data.*.id']],
        'get-record' => [['type' => 'record', 'model_key' => 'input.resource', 'id' => 'input.id']],
    ]]);

    expect(extractedFrom([
        ['list-users', ['search' => 'ana'], json_encode(['data' => [['id' => 31, 'name' => 'Ana']], 'meta' => ['total' => 1]])],
        ['list-users', ['search' => 'a'], json_encode(['data' => [['id' => 32], ['id' => 33]]])],
        ['list-users', ['search' => 'zz'], json_encode(['data' => []])],
        ['list-users', ['search' => 'eva'], json_encode(['data' => [['id' => 34]]]), true],
        ['list-users', ['search' => 'x'], 'No users match.'],
        ['get-record', ['resource' => 'staff', 'id' => 5], '{}'],
    ]))->toBe(['user::31', 'record:staff:5']);
});
