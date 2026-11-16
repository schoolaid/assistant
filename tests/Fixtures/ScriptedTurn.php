<?php

namespace SchoolAid\Assistant\Tests\Fixtures;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SchoolAid\Assistant\Contracts\AccessGate;
use SchoolAid\Assistant\Contracts\BudgetAlerts;
use SchoolAid\Assistant\Contracts\Instructions;
use SchoolAid\Assistant\Contracts\PanelScope;
use SchoolAid\Assistant\Contracts\ToolSource;
use SchoolAid\Assistant\Contracts\WritePreviews;
use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Enums\AssistantTurnStatusEnum;
use SchoolAid\Assistant\Enums\StepOutcomeEnum;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Services\Agent\AssistantTurnRunner;
use SchoolAid\Assistant\Services\Agent\ConversationSummary;
use SchoolAid\Assistant\Services\Agent\ConversationTranscript;
use SchoolAid\Assistant\Services\Tools\ToolBridge;

require_once __DIR__.'/TurnDoubles.php';

/**
 * Runs whole turns of the engine against a scripted Messages API, in workspace '1' of the
 * single-database app. The rows are created the way {@see \SchoolAid\Assistant\Services\AssistantMessageSender}
 * and the runner write them; the sender itself is not used (its budget and settings gates are not
 * what these tests are about).
 */
trait ScriptedTurn
{
    public const WORKSPACE = '1';

    public const USER_ID = 7;

    /**
     * @var list<array<string, mixed>>
     */
    private array $sentRequests = [];

    public ?Closure $onApiCall = null;

    public function bindTurnDoubles(): FakeToolBridge
    {
        $bridge = new FakeToolBridge();

        $this->app->instance(ToolBridge::class, $bridge);
        $this->app->singleton(PanelScope::class, FakePanelScope::class);
        $this->app->singleton(Instructions::class, FakeInstructions::class);
        $this->app->singleton(AccessGate::class, FakeAccessGate::class);
        $this->app->singleton(BudgetAlerts::class, FakeBudgetAlerts::class);
        $this->app->singleton(WritePreviews::class, FakeWritePreviews::class);
        $this->app->singleton(ToolSource::class, FakeToolSource::class);

        config([
            HostServiceProvider::NAME.'.anthropic.api_key' => 'sk-test',
            HostServiceProvider::NAME.'.anthropic.url' => 'https://api.anthropic.com/v1',
            HostServiceProvider::NAME.'.model' => 'claude-sonnet-5-5',
            HostServiceProvider::NAME.'.tool_search.enabled' => false,
        ]);

        return $bridge;
    }

    /**
     * The API answers these in order; a Closure answers with what it returns (an array is a 200
     * body, anything else is returned as it is). Every body sent is kept for {@see self::sentRequests()}.
     *
     * @param  list<array<string, mixed>|Closure>  $responses
     */
    public function scriptApi(array $responses): void
    {
        $this->sentRequests = [];

        Http::fake(['api.anthropic.com/*' => function (Request $request) use (&$responses) {
            $this->sentRequests[] = $request->data();

            if ($this->onApiCall !== null) {
                ($this->onApiCall)($request);
            }

            $next = array_shift($responses);

            if ($next === null) {
                return Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'The test scripted no more responses.']], 400);
            }

            $next = $next instanceof Closure ? $next($request) : $next;

            return is_array($next) ? Http::response($next, 200) : $next;
        }]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sentRequests(): array
    {
        return $this->sentRequests;
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    public function apiResponse(array $content, string $stopReason = 'end_turn', int $input = 100, int $output = 20, int $cacheRead = 0, int $cacheWrite = 0): array
    {
        return [
            'id' => 'msg_'.Str::random(10),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => $content,
            'stop_reason' => $stopReason,
            'usage' => [
                'input_tokens' => $input,
                'output_tokens' => $output,
                'cache_read_input_tokens' => $cacheRead,
                'cache_creation_input_tokens' => $cacheWrite,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function textResponse(string $text, int $input = 100, int $output = 20): array
    {
        return $this->apiResponse([['type' => 'text', 'text' => $text]], 'end_turn', $input, $output);
    }

    public function conversation(): AssistantConversation
    {
        return $this->inWorkspace(fn (): AssistantConversation => AssistantConversation::factory()->create([
            'user_id' => self::USER_ID,
            'system_prompt' => FakeInstructions::SYSTEM,
        ]));
    }

    /**
     * A turn that already ended: what the person asked and the answer of the model, whose request
     * measured $promptTokens.
     */
    public function finishedTurn(AssistantConversation $conversation, string $ask, string $answer, int $promptTokens): AssistantTurn
    {
        return $this->inWorkspace(function () use ($conversation, $ask, $answer, $promptTokens): AssistantTurn {
            $turn = $this->turn($conversation, AssistantTurnStatusEnum::Completed);
            $this->personMessage($conversation, $turn, $ask);

            AssistantMessage::factory()->create([
                'conversation_id' => $conversation->id, 'turn_id' => $turn->id, 'role' => 'assistant', 'kind' => AssistantMessageKindEnum::Text,
                'content' => [['type' => 'text', 'text' => $answer]], 'display_text' => $answer,
                'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'input_tokens' => $promptTokens, 'output_tokens' => 20,
            ]);

            return $turn;
        });
    }

    /**
     * A queued turn opened by a message of the person.
     */
    public function personTurn(AssistantConversation $conversation, string $text): AssistantTurn
    {
        return $this->inWorkspace(function () use ($conversation, $text): AssistantTurn {
            $turn = $this->turn($conversation, AssistantTurnStatusEnum::Queued);
            $this->personMessage($conversation, $turn, $text);

            return $turn;
        });
    }

    /**
     * A queued follow-up turn, as Confirm or Reject opens it: an internal note and no context.
     */
    public function followUpTurn(AssistantConversation $conversation, string $note): AssistantTurn
    {
        return $this->inWorkspace(function () use ($conversation, $note): AssistantTurn {
            $turn = $this->turn($conversation, AssistantTurnStatusEnum::Queued);

            AssistantMessage::factory()->create([
                'conversation_id' => $conversation->id, 'turn_id' => $turn->id, 'role' => 'user', 'kind' => AssistantMessageKindEnum::Internal,
                'content' => [['type' => 'text', 'text' => $note]], 'display_text' => null,
            ]);

            return $turn;
        });
    }

    /**
     * A stored summary that worked: it replaces every message up to $throughMessageId.
     */
    public function summaryRow(AssistantConversation $conversation, AssistantTurn $turn, string $text, int $throughMessageId): AssistantMessage
    {
        return $this->inWorkspace(fn (): AssistantMessage => AssistantMessage::factory()->create([
            'conversation_id' => $conversation->id, 'turn_id' => $turn->id, 'role' => 'user', 'kind' => 'summary',
            'content' => [['type' => 'text', 'text' => ConversationSummary::HEADER.$text]],
            'payload' => ['through_message_id' => $throughMessageId], 'display_text' => null,
        ]));
    }

    /**
     * A stored attempt that produced no summary.
     */
    public function failedSummaryRow(AssistantConversation $conversation, AssistantTurn $turn, string $reason = 'refusal'): AssistantMessage
    {
        return $this->inWorkspace(fn (): AssistantMessage => AssistantMessage::factory()->create([
            'conversation_id' => $conversation->id, 'turn_id' => $turn->id, 'role' => 'user', 'kind' => 'summary',
            'content' => [], 'payload' => ['failed' => true, 'reason' => $reason], 'display_text' => null,
        ]));
    }

    public function lastMessageId(AssistantConversation $conversation): int
    {
        return (int) $this->inWorkspace(fn () => AssistantMessage::query()->where('conversation_id', $conversation->id)->max('id'));
    }

    /**
     * The history as the engine would send it, as one JSON string to search in.
     */
    public function history(AssistantConversation $conversation, ?int $beforeMessageId = null): string
    {
        return json_encode($this->apiMessages($conversation, $beforeMessageId), JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    public function apiMessages(AssistantConversation $conversation, ?int $beforeMessageId = null): array
    {
        return $this->inWorkspace(fn (): array => app(ConversationTranscript::class)->toApiMessages($conversation, $beforeMessageId));
    }

    public function step(AssistantTurn $turn): StepOutcomeEnum
    {
        return $this->inWorkspace(fn (): StepOutcomeEnum => app(AssistantTurnRunner::class)->step(AssistantTurn::query()->findOrFail($turn->id)));
    }

    public function inWorkspace(Closure $callback): mixed
    {
        return $this->workspace()->run(self::WORKSPACE, $callback);
    }

    private function turn(AssistantConversation $conversation, AssistantTurnStatusEnum $status): AssistantTurn
    {
        return AssistantTurn::factory()->create([
            'conversation_id' => $conversation->id,
            'status' => $status,
            'panel_context' => ['user_id' => self::USER_ID],
            'iterations' => $status === AssistantTurnStatusEnum::Completed ? 1 : 0,
            'step_started_at' => null,
        ]);
    }

    private function personMessage(AssistantConversation $conversation, AssistantTurn $turn, string $text): AssistantMessage
    {
        $opens = ! AssistantMessage::query()->where('conversation_id', $conversation->id)->exists();

        return AssistantMessage::factory()->create([
            'conversation_id' => $conversation->id, 'turn_id' => $turn->id, 'role' => 'user', 'kind' => AssistantMessageKindEnum::Text,
            'content' => array_map(fn (string $block): array => ['type' => 'text', 'text' => $block], [
                ...($opens ? [FakeInstructions::CONVERSATION_CONTEXT] : []),
                FakeInstructions::TURN_CONTEXT,
                $text,
            ]),
            'display_text' => $text,
        ]);
    }
}
