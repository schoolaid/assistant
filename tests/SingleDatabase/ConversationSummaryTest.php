<?php

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use SchoolAid\Assistant\Enums\AssistantTurnStatusEnum;
use SchoolAid\Assistant\Enums\StepOutcomeEnum;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Models\AssistantUsage;
use SchoolAid\Assistant\Services\Agent\ConversationSummary;
use SchoolAid\Assistant\Support\ConversationLimit;
use SchoolAid\Assistant\Tests\Fixtures\FakeInstructions;
use SchoolAid\Assistant\Tests\Fixtures\HostServiceProvider;
use SchoolAid\Assistant\Tests\Fixtures\ScriptedTurn;

/*
| A long conversation is summarized at the start of a turn instead of being closed: the engine asks
| the model for the summary itself, stores it as a `summary` message and builds the history from it.
| Off unless the app sets `summarize_at_input_tokens`.
*/

uses(ScriptedTurn::class);

beforeEach(function () {
    $this->bridge = $this->bindTurnDoubles();
});

it('sends the whole stored conversation while summaries are off', function () {
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, 'ventas de hoy', 'Fueron Q 1,200.', promptTokens: 120000);
    $this->scriptApi([$this->textResponse('Listo.')]);

    $turn = $this->personTurn($conversation, 'y ayer');

    expect($this->step($turn))->toBe(StepOutcomeEnum::Completed)
        ->and($this->sentRequests())->toHaveCount(1)
        ->and(json_encode($this->sentRequests()[0]['messages'], JSON_UNESCAPED_UNICODE))->toContain('ventas de hoy')
        ->and($this->inWorkspace(fn (): bool => AssistantMessage::query()->where('kind', 'summary')->exists()))->toBeFalse();
});

/**
 * Turns summaries on at $tokens for this test.
 */
function summarizeAt(mixed $tokens): void
{
    config([HostServiceProvider::NAME.'.summarize_at_input_tokens' => $tokens]);
}

it('hides a summary from the panel', function () {
    $conversation = $this->conversation();
    $turn = $this->finishedTurn($conversation, 'ventas de hoy', 'Fueron Q 1,200.', promptTokens: 1200);
    $this->summaryRow($conversation, $turn, 'Vio las ventas.', $this->lastMessageId($conversation));

    $visible = $this->inWorkspace(fn (): array => AssistantMessage::query()->visibleInPanel()->pluck('kind')->map->value->all());

    expect($visible)->toBe(['text', 'text']);
});

it('starts the history at the latest successful summary', function () {
    $conversation = $this->conversation();
    $first = $this->finishedTurn($conversation, 'ventas de hoy', 'Fueron Q 1,200.', promptTokens: 1200);
    $this->summaryRow($conversation, $first, 'Vio las ventas de hoy.', $this->lastMessageId($conversation));
    $this->personTurn($conversation, 'y ayer');

    $messages = $this->apiMessages($conversation);
    $history = $this->history($conversation);

    expect($history)->not->toContain('ventas de hoy"')
        ->and($history)->not->toContain('Fueron Q 1,200.')
        ->and($messages[0]['role'])->toBe('user')
        ->and($messages[0]['content'][0]['text'])->toBe(ConversationSummary::HEADER.'Vio las ventas de hoy.')
        ->and($history)->toContain('y ayer')
        ->and($messages)->toHaveCount(1);
});

it('never sends a failed attempt, and uses the last successful summary after one', function () {
    $conversation = $this->conversation();
    $first = $this->finishedTurn($conversation, 'ventas de hoy', 'Fueron Q 1,200.', promptTokens: 1200);
    $summary = $this->summaryRow($conversation, $first, 'Vio las ventas de hoy.', $this->lastMessageId($conversation));
    $second = $this->finishedTurn($conversation, 'y ayer', 'Ayer fueron Q 900.', promptTokens: 1300);
    $this->failedSummaryRow($conversation, $second);

    $history = $this->history($conversation);
    $summaries = app(ConversationSummary::class);

    expect($history)->toContain('Vio las ventas de hoy.')
        ->and($history)->toContain('Ayer fueron Q 900.')
        ->and(substr_count($history, 'Resumen de la conversación'))->toBe(1)
        ->and($this->inWorkspace(fn () => $summaries->latest($conversation)?->id))->toBe($summary->id)
        ->and($this->inWorkspace(fn () => $summaries->lastAttemptFailedEach([$conversation->id])))->toBe([$conversation->id => true]);
});

it('cuts the history before a message when asked', function () {
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, 'ventas de hoy', 'Fueron Q 1,200.', promptTokens: 1200);
    $turn = $this->personTurn($conversation, 'y ayer');
    $firstOfTurn = (int) $this->inWorkspace(fn () => AssistantMessage::query()->where('turn_id', $turn->id)->min('id'));

    expect($this->history($conversation, $firstOfTurn))->toContain('Fueron Q 1,200.')
        ->and($this->history($conversation, $firstOfTurn))->not->toContain('y ayer');
});

it('reads the threshold from config, off unless it is a positive number', function (mixed $value, ?int $expected) {
    summarizeAt($value);

    expect(app(ConversationSummary::class)->threshold())->toBe($expected);
})->with([
    'unset' => [null, null],
    'zero' => [0, null],
    'negative' => [-1, null],
    'not a number' => ['abc', null],
    'a numeric string' => ['100000', 100000],
    'a number' => [100000, 100000],
]);

it('is due only at the start of a turn over the threshold', function () {
    summarizeAt(1000);
    $summaries = app(ConversationSummary::class);
    $due = fn ($turn): bool => $this->inWorkspace(fn (): bool => $summaries->due($turn->refresh()));

    $over = $this->conversation();
    $this->finishedTurn($over, 'ventas de hoy', 'Fueron Q 1,200.', promptTokens: 1200);
    $turn = $this->personTurn($over, 'y ayer');

    $under = $this->conversation();
    $this->finishedTurn($under, 'ventas de hoy', 'Fueron Q 1,200.', promptTokens: 900);
    $small = $this->personTurn($under, 'y ayer');

    expect($due($turn))->toBeTrue()
        ->and($due($small))->toBeFalse();

    $this->failedSummaryRow($over, $turn);
    expect($due($turn))->toBeFalse();

    $answered = $this->conversation();
    $midTurn = $this->finishedTurn($answered, 'ventas de hoy', 'Fueron Q 1,200.', promptTokens: 1200);
    expect($due($midTurn))->toBeFalse();

    summarizeAt(null);
    $off = $this->conversation();
    $this->finishedTurn($off, 'ventas de hoy', 'Fueron Q 1,200.', promptTokens: 1200);
    expect($due($this->personTurn($off, 'y ayer')))->toBeFalse();
});

/**
 * The one `summary` row of the conversation.
 */
function storedSummary($conversation): AssistantMessage
{
    return test()->inWorkspace(fn (): AssistantMessage => AssistantMessage::query()
        ->where('conversation_id', $conversation->id)->where('kind', 'summary')->sole());
}

it('summarizes first when a turn starts over the threshold, then answers on the summarized history', function () {
    summarizeAt(1000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 1200);
    $through = $this->lastMessageId($conversation);
    $this->scriptApi([
        $this->textResponse('Vio las ventas de hoy: Q 1,200.', input: 1200, output: 80),
        $this->textResponse('Ayer fueron Q 900.'),
    ]);

    $turn = $this->personTurn($conversation, 'y ayer');

    expect($this->step($turn))->toBe(StepOutcomeEnum::Continue)
        ->and($this->step($turn))->toBe(StepOutcomeEnum::Completed)
        ->and($this->sentRequests())->toHaveCount(2);

    [$summaryRequest, $nextRequest] = $this->sentRequests();
    $asked = json_encode($summaryRequest['messages'], JSON_UNESCAPED_UNICODE);
    $last = end($summaryRequest['messages']);

    expect($last['role'])->toBe('user')
        ->and(end($last['content'])['text'])->toBe(ConversationSummary::DEFAULT_INSTRUCTIONS)
        ->and($asked)->toContain('¿cuánto se vendió hoy?')
        ->and($asked)->toContain('Fueron Q 1,200.')
        ->and($asked)->not->toContain('y ayer')
        ->and($summaryRequest)->not->toHaveKey('tool_choice')
        ->and($summaryRequest['max_tokens'])->toBe(8192)
        ->and(json_encode(array_diff_key($summaryRequest, ['messages' => true, 'max_tokens' => true])))
        ->toBe(json_encode(array_diff_key($nextRequest, ['messages' => true, 'max_tokens' => true])));

    $sent = json_encode($nextRequest['messages'], JSON_UNESCAPED_UNICODE);

    expect($sent)->not->toContain('¿cuánto se vendió hoy?')
        ->and($sent)->not->toContain('Fueron Q 1,200.')
        ->and($sent)->not->toContain('Resume esta conversación')
        ->and($sent)->toContain('Resumen de la conversación')
        ->and($sent)->toContain('Vio las ventas de hoy: Q 1,200.')
        ->and($sent)->toContain('y ayer');

    $summary = storedSummary($conversation);

    expect($summary->role)->toBe('user')
        ->and($summary->model)->toBeNull()
        ->and($summary->display_text)->toBeNull()
        ->and($summary->turn_id)->toBe($turn->id)
        ->and($summary->payload['through_message_id'])->toBe($through)
        ->and($summary->input_tokens)->toBe(1200)
        ->and($summary->output_tokens)->toBe(80)
        ->and($this->inWorkspace(fn () => AssistantTurn::query()->find($turn->id)->iterations))->toBe(1);
});

it('counts the summary call against the budget', function () {
    summarizeAt(1000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 1200);
    $this->scriptApi([
        $this->textResponse('Vio las ventas de hoy.', input: 1200, output: 80),
        $this->textResponse('Ayer fueron Q 900.', input: 300, output: 20),
    ]);
    $turn = $this->personTurn($conversation, 'y ayer');

    $this->step($turn);
    $this->step($turn);

    expect((int) AssistantUsage::query()->sum('input_tokens'))->toBe(1500)
        ->and((int) AssistantUsage::query()->sum('output_tokens'))->toBe(100);
});

it('carries who is writing and the last turn context in front of the summary', function () {
    summarizeAt(1000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 1200);
    $this->scriptApi([$this->textResponse('Vio las ventas de hoy.')]);

    $this->step($this->personTurn($conversation, 'y ayer'));

    expect(array_column(storedSummary($conversation)->content, 'text'))->toBe([
        FakeInstructions::CONVERSATION_CONTEXT,
        FakeInstructions::TURN_CONTEXT,
        ConversationSummary::HEADER.'Vio las ventas de hoy.',
    ]);
});

it('summarizes again from the previous summary', function () {
    summarizeAt(1000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 1200);
    $this->scriptApi([
        $this->textResponse('Primer resumen: vio las ventas de hoy.'),
        $this->textResponse('Ayer fueron Q 900.', input: 1500),
        $this->textResponse('Segundo resumen: comparó hoy con ayer.'),
    ]);
    $second = $this->personTurn($conversation, 'y ayer');
    $this->step($second);
    $this->step($second);

    $third = $this->personTurn($conversation, 'y la semana pasada');
    expect($this->step($third))->toBe(StepOutcomeEnum::Continue);

    $asked = json_encode($this->sentRequests()[2]['messages'], JSON_UNESCAPED_UNICODE);
    $latest = $this->inWorkspace(fn (): AssistantMessage => app(ConversationSummary::class)->latest($conversation));

    expect($asked)->toContain('Primer resumen: vio las ventas de hoy.')
        ->and($asked)->toContain('Ayer fueron Q 900.')
        ->and($asked)->not->toContain('¿cuánto se vendió hoy?')
        ->and($asked)->not->toContain('y la semana pasada')
        ->and(array_column($latest->content, 'text'))->toBe([
            FakeInstructions::CONVERSATION_CONTEXT,
            FakeInstructions::TURN_CONTEXT,
            ConversationSummary::HEADER.'Segundo resumen: comparó hoy con ayer.',
        ])
        ->and($this->history($conversation))->not->toContain('Primer resumen');
});

it('summarizes a turn that Confirm or Reject opened, which brings no context of its own', function () {
    summarizeAt(1000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, 'anula la orden 5', 'Te muestro la vista previa.', promptTokens: 1200);
    $this->scriptApi([$this->textResponse('Pidió anular la orden 5.'), $this->textResponse('Anulada.')]);

    $turn = $this->followUpTurn($conversation, 'La persona confirmó: orden 5 anulada.');

    expect($this->step($turn))->toBe(StepOutcomeEnum::Continue)
        ->and($this->step($turn))->toBe(StepOutcomeEnum::Completed);

    $sent = json_encode($this->sentRequests()[1]['messages'], JSON_UNESCAPED_UNICODE);

    expect($sent)->toContain(FakeInstructions::CONVERSATION_CONTEXT)
        ->and($sent)->toContain(FakeInstructions::TURN_CONTEXT)
        ->and($sent)->toContain('Pidió anular la orden 5.')
        ->and($sent)->toContain('La persona confirmó: orden 5 anulada.');
});

// Review Focus 1
it('still summarizes a conversation whose first message has no context blocks', function () {
    summarizeAt(1000);
    $conversation = $this->conversation();
    $this->inWorkspace(fn () => AssistantMessage::factory()->create([
        'conversation_id' => $conversation->id, 'role' => 'assistant', 'kind' => 'text',
        'content' => [['type' => 'text', 'text' => 'Hola, ¿en qué te ayudo?']], 'display_text' => 'Hola, ¿en qué te ayudo?',
        'model' => 'claude-sonnet-5-5', 'input_tokens' => 1200,
    ]));
    $this->scriptApi([$this->textResponse('Solo hubo un saludo.')]);

    expect($this->step($this->followUpTurn($conversation, 'sigue')))->toBe(StepOutcomeEnum::Continue)
        ->and(array_column(storedSummary($conversation)->content, 'text'))->toBe([ConversationSummary::HEADER.'Solo hubo un saludo.']);
});

// Review Focus 2
it('stores nothing when the step claim was lost while the summary was being written', function () {
    summarizeAt(1000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 1200);
    $turn = $this->personTurn($conversation, 'y ayer');
    $this->scriptApi([$this->textResponse('Vio las ventas de hoy.')]);
    $this->onApiCall = fn () => $this->inWorkspace(fn () => AssistantTurn::query()->whereKey($turn->id)->update(['step_started_at' => now()->addMinute()]));

    expect($this->step($turn))->toBe(StepOutcomeEnum::Skipped)
        ->and(json_encode($this->sentRequests()[0]['messages'], JSON_UNESCAPED_UNICODE))->toContain('Resume esta conversación')
        ->and($this->inWorkspace(fn (): bool => AssistantMessage::query()->where('kind', 'summary')->exists()))->toBeFalse();
});

/**
 * Whether the conversation is closed for a new message, Confirm or Reject.
 */
function closedBySize($conversation): bool
{
    return test()->inWorkspace(fn (): bool => app(ConversationLimit::class)->reached($conversation->id));
}

function sizeCapAt(int $tokens): void
{
    config([HostServiceProvider::NAME.'.max_conversation_input_tokens' => $tokens]);
}

it('keeps going on the full history when the summary fails', function (array $content, string $stopReason, string $reason) {
    summarizeAt(1000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 1200);
    $this->scriptApi([$this->apiResponse($content, $stopReason, input: 1200, output: 40), $this->textResponse('Ayer fueron Q 900.')]);

    $turn = $this->personTurn($conversation, 'y ayer');

    expect($this->step($turn))->toBe(StepOutcomeEnum::Continue)
        ->and($this->step($turn))->toBe(StepOutcomeEnum::Completed)
        ->and($this->sentRequests())->toHaveCount(2);

    $sent = json_encode($this->sentRequests()[1]['messages'], JSON_UNESCAPED_UNICODE);
    $attempt = storedSummary($conversation);

    expect($sent)->toContain('¿cuánto se vendió hoy?')
        ->and($sent)->toContain('Fueron Q 1,200.')
        ->and($sent)->toContain('y ayer')
        ->and($sent)->not->toContain('Resume esta conversación')
        ->and($attempt->content)->toBe([])
        ->and($attempt->payload)->toBe(['failed' => true, 'reason' => $reason])
        ->and($attempt->model)->toBeNull()
        ->and($attempt->input_tokens)->toBe(1200)
        ->and($this->bridge->calls)->toBe([])
        ->and($this->inWorkspace(fn () => AssistantTurn::query()->find($turn->id)->status))->toBe(AssistantTurnStatusEnum::Completed)
        ->and($this->inWorkspace(fn () => AssistantTurn::query()->find($turn->id)->iterations))->toBe(1);
})->with([
    'a refusal' => [[], 'refusal', 'refusal'],
    'a summary cut off' => [[['type' => 'text', 'text' => 'Vio las ven']], 'max_tokens', 'max_tokens'],
    'a tool call instead of a summary' => [[['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'list-sales', 'input' => ['day' => 'today']]], 'tool_use', 'tool_use'],
    'only whitespace' => [[['type' => 'text', 'text' => "  \n"]], 'end_turn', 'empty'],
    'only thinking' => [[['type' => 'thinking', 'thinking' => '', 'signature' => 'sig']], 'end_turn', 'empty'],
]);

it('keeps going when the API rejects the summary request for its size', function () {
    summarizeAt(1000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 1200);
    $this->scriptApi([
        fn () => Http::response(['type' => 'error', 'error' => ['type' => 'request_too_large', 'message' => 'Request exceeds the maximum size']], 413),
        $this->textResponse('Ayer fueron Q 900.'),
    ]);
    $turn = $this->personTurn($conversation, 'y ayer');

    expect($this->step($turn))->toBe(StepOutcomeEnum::Continue)
        ->and(storedSummary($conversation)->payload)->toBe(['failed' => true, 'reason' => 'rejected'])
        ->and($this->step($turn))->toBe(StepOutcomeEnum::Completed);
});

it('fails the turn like any API failure on a transient error, leaving no summary attempt', function () {
    Sleep::fake();
    summarizeAt(1000);
    sizeCapAt(1100);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 1200);
    $overloaded = fn () => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529);
    $this->scriptApi([$overloaded, $overloaded]);

    $turn = $this->personTurn($conversation, 'y ayer');

    expect($this->step($turn))->toBe(StepOutcomeEnum::Failed)
        ->and($this->inWorkspace(fn (): bool => AssistantMessage::query()->where('kind', 'summary')->exists()))->toBeFalse()
        ->and(closedBySize($conversation))->toBeFalse();

    $retry = $this->personTurn($conversation, 'y ayer');

    expect($this->inWorkspace(fn (): bool => app(ConversationSummary::class)->due($retry)))->toBeTrue();
});

it('closes an over-the-cap conversation only after a failed attempt', function () {
    summarizeAt(1000);
    sizeCapAt(2000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 2500);

    expect(closedBySize($conversation))->toBeFalse();

    $this->scriptApi([$this->apiResponse([], 'refusal'), $this->textResponse('Ayer fueron Q 900.', input: 2600)]);
    $turn = $this->personTurn($conversation, 'y ayer');
    $this->step($turn);
    $this->step($turn);

    expect(closedBySize($conversation))->toBeTrue();
});

it('lets a turn opened by Confirm over the cap run and get its answer even if the summary fails', function () {
    summarizeAt(1000);
    sizeCapAt(2000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, 'anula la orden 5', 'Te muestro la vista previa.', promptTokens: 2500);
    $this->scriptApi([$this->apiResponse([], 'refusal'), $this->textResponse('La orden 5 quedó anulada.', input: 2600)]);

    expect(closedBySize($conversation))->toBeFalse();

    $turn = $this->followUpTurn($conversation, 'La persona confirmó: orden 5 anulada.');

    expect($this->step($turn))->toBe(StepOutcomeEnum::Continue)
        ->and($this->step($turn))->toBe(StepOutcomeEnum::Completed)
        ->and($this->inWorkspace(fn () => AssistantMessage::query()->where('turn_id', $turn->id)->where('role', 'assistant')->value('display_text')))
        ->toBe('La orden 5 quedó anulada.');
});

// Review Focus 3
it('looks only at the latest attempt for the cap, and at the latest success for the history', function () {
    summarizeAt(1000);
    sizeCapAt(2000);
    $conversation = $this->conversation();
    $first = $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 1200);
    $this->failedSummaryRow($conversation, $first);
    $second = $this->finishedTurn($conversation, 'y ayer', 'Ayer fueron Q 900.', promptTokens: 2500);

    expect(closedBySize($conversation))->toBeTrue();

    $this->summaryRow($conversation, $second, 'Comparó hoy con ayer.', $this->lastMessageId($conversation));

    expect(closedBySize($conversation))->toBeFalse()
        ->and($this->history($conversation))->toContain('Comparó hoy con ayer.')
        ->and($this->history($conversation))->not->toContain('Ayer fueron Q 900.');
});

it('behaves as before with summaries off', function () {
    sizeCapAt(2000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 2500);

    expect(closedBySize($conversation))->toBeTrue();

    summarizeAt(1000);

    expect(closedBySize($conversation))->toBeFalse();
});

/*
| Found by the review of the whole change, before the first release.
*/

it('treats any 400 on the summary request as a failed summary, not as a failed turn', function () {
    summarizeAt(1000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 1200);
    $this->scriptApi([
        fn () => Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'messages.3: unexpected block']], 400),
        $this->textResponse('Ayer fueron Q 900.'),
    ]);
    $turn = $this->personTurn($conversation, 'y ayer');

    expect($this->step($turn))->toBe(StepOutcomeEnum::Continue)
        ->and(storedSummary($conversation)->payload)->toBe(['failed' => true, 'reason' => 'rejected'])
        ->and($this->step($turn))->toBe(StepOutcomeEnum::Completed);
});

it('leaves no server tool call open in what it sends to the summarizer', function () {
    summarizeAt(1000);
    $conversation = $this->conversation();
    $died = $this->personTurn($conversation, 'busca la tool de ventas', status: AssistantTurnStatusEnum::Failed);
    $this->inWorkspace(fn () => AssistantMessage::factory()->create([
        'conversation_id' => $conversation->id, 'turn_id' => $died->id, 'role' => 'assistant', 'kind' => 'text',
        'content' => [
            ['type' => 'text', 'text' => 'Busco la tool.'],
            ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'tool_search_tool_bm25', 'input' => ['query' => 'ventas']],
        ],
        'display_text' => 'Busco la tool.', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'pause_turn', 'input_tokens' => 1200,
    ]));
    $this->scriptApi([$this->textResponse('Pidió buscar la tool de ventas.')]);

    expect($this->step($this->personTurn($conversation, 'sigue')))->toBe(StepOutcomeEnum::Continue);

    $asked = json_encode($this->sentRequests()[0]['messages'], JSON_UNESCAPED_UNICODE);

    expect($asked)->toContain('Busco la tool.')
        ->and($asked)->not->toContain('srvtoolu_1');
});

it('falls back to its own cap when summary.max_tokens is not a positive number', function (mixed $configured) {
    summarizeAt(1000);
    config([HostServiceProvider::NAME.'.summary.max_tokens' => $configured]);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 1200);
    $this->scriptApi([$this->textResponse('Vio las ventas de hoy.')]);

    $this->step($this->personTurn($conversation, 'y ayer'));

    expect($this->sentRequests()[0]['max_tokens'])->toBe(8192);
})->with(['a word' => ['abc'], 'negative' => [-5], 'zero' => [0], 'unset' => [null]]);

it('carries the context of the last message of the person into the next summary', function () {
    summarizeAt(1000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 1200, turnContext: '[Momento] lunes, sucursal Norte.');
    $this->scriptApi([
        $this->textResponse('Primer resumen.'),
        $this->textResponse('Te muestro la vista previa.', input: 1500),
        $this->textResponse('Segundo resumen.'),
    ]);
    $second = $this->personTurn($conversation, 'anula la orden 5', turnContext: '[Momento] martes, sucursal Sur.');
    $this->step($second);
    $this->step($second);

    expect($this->step($this->followUpTurn($conversation, 'La persona confirmó: orden 5 anulada.')))->toBe(StepOutcomeEnum::Continue);

    $latest = $this->inWorkspace(fn (): AssistantMessage => app(ConversationSummary::class)->latest($conversation));

    expect(array_column($latest->content, 'text'))->toBe([
        FakeInstructions::CONVERSATION_CONTEXT,
        '[Momento] martes, sucursal Sur.',
        ConversationSummary::HEADER.'Segundo resumen.',
    ]);
});

it('does not summarize the summary again when the turn that wrote it failed afterwards', function () {
    Sleep::fake();
    summarizeAt(1000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 1200);
    $overloaded = fn () => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529);
    $this->scriptApi([$this->textResponse('Vio las ventas de hoy.'), $overloaded, $overloaded, $this->textResponse('Ayer fueron Q 900.')]);
    $failed = $this->personTurn($conversation, 'y ayer');

    expect($this->step($failed))->toBe(StepOutcomeEnum::Continue)
        ->and($this->step($failed))->toBe(StepOutcomeEnum::Failed);

    $retry = $this->personTurn($conversation, 'y ayer');

    expect($this->inWorkspace(fn (): bool => app(ConversationSummary::class)->due($retry)))->toBeFalse()
        ->and($this->step($retry))->toBe(StepOutcomeEnum::Completed)
        ->and($this->inWorkspace(fn (): int => AssistantMessage::query()->where('kind', 'summary')->count()))->toBe(1);
});

it('reports a summary that failed for a reason of ours, and only logs a refusal', function (string $stopReason, bool $reported) {
    Exceptions::fake();
    summarizeAt(1000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 1200);
    $this->scriptApi([$this->apiResponse([], $stopReason)]);

    $this->step($this->personTurn($conversation, 'y ayer'));

    $reported ? Exceptions::assertReported(UnexpectedValueException::class) : Exceptions::assertNothingReported();
})->with(['cut off by its own cap' => ['max_tokens', true], 'no text' => ['end_turn', true], 'a refusal' => ['refusal', false]]);

it('adds the request for the summary to a history that already ends with the person', function () {
    summarizeAt(1000);
    $conversation = $this->conversation();
    $this->finishedTurn($conversation, '¿cuánto se vendió hoy?', 'Fueron Q 1,200.', promptTokens: 1200);
    $this->personTurn($conversation, 'y la semana pasada', status: AssistantTurnStatusEnum::Failed);
    $this->scriptApi([$this->textResponse('Vio las ventas de hoy y pidió la semana pasada.')]);

    expect($this->step($this->personTurn($conversation, 'y ayer')))->toBe(StepOutcomeEnum::Continue);

    $messages = $this->sentRequests()[0]['messages'];
    $last = end($messages);

    expect(array_column($messages, 'role'))->toBe(['user', 'assistant', 'user'])
        ->and(json_encode($last['content'], JSON_UNESCAPED_UNICODE))->toContain('y la semana pasada')
        ->and(end($last['content'])['text'])->toBe(ConversationSummary::DEFAULT_INSTRUCTIONS);
});
