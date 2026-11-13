<?php

namespace SchoolAid\Assistant\Database\Factories;

use SchoolAid\Assistant\Models\AssistantTurn;
use Illuminate\Database\Eloquent\Factories\Factory;
use SchoolAid\Assistant\Enums\AssistantTurnStatusEnum;
use SchoolAid\Assistant\Models\AssistantConversation;
use Illuminate\Support\Str;

/**
 * @extends Factory<AssistantTurn>
 */
class AssistantTurnFactory extends Factory
{
    protected $model = AssistantTurn::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conversation_id' => AssistantConversation::factory(),
            'run_id' => 'assistant-'.Str::uuid(),
            'status' => AssistantTurnStatusEnum::Queued,
            'panel_context' => ['route' => '/orders'],
            'iterations' => 0,
            'error' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
