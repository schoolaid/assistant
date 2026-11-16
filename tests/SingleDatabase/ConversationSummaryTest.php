<?php

use SchoolAid\Assistant\Enums\StepOutcomeEnum;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Services\Agent\ConversationSummary;
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
