<?php

use SchoolAid\Assistant\Enums\StepOutcomeEnum;
use SchoolAid\Assistant\Models\AssistantMessage;
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
