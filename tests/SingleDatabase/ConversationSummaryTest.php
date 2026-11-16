<?php

use SchoolAid\Assistant\Enums\StepOutcomeEnum;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Models\AssistantUsage;
use SchoolAid\Assistant\Services\Agent\ConversationSummary;
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
        ->and($this->inWorkspace(fn (): bool => AssistantMessage::query()->where('kind', 'summary')->exists()))->toBeFalse();
});
