<?php

namespace SchoolAid\Assistant\Database\Factories;

use SchoolAid\Assistant\Models\AssistantConfirmation;
use Illuminate\Database\Eloquent\Factories\Factory;
use SchoolAid\Assistant\Enums\AssistantConfirmationStatusEnum;
use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Models\AssistantTurn;
use Illuminate\Support\Str;

/**
 * @extends Factory<AssistantConfirmation>
 */
class AssistantConfirmationFactory extends Factory
{
    protected $model = AssistantConfirmation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'turn_id' => AssistantTurn::factory(),
            'conversation_id' => fn (array $attributes) => AssistantTurn::query()->find($attributes['turn_id'])->conversation_id,
            'message_id' => fn (array $attributes) => AssistantMessage::factory()->create([
                'conversation_id' => $attributes['conversation_id'],
                'turn_id' => $attributes['turn_id'],
                'kind' => AssistantMessageKindEnum::Confirmation,
                'role' => 'assistant',
            ])->id,
            'tool' => 'cancel-order',
            'arguments' => ['orderId' => 1],
            'summary' => $this->faker->sentence(),
            'preview' => ['summary' => $this->faker->sentence()],
            'token' => Str::random(40),
            'expires_at' => now()->addMinutes(15),
            'status' => AssistantConfirmationStatusEnum::Pending,
            'result' => null,
            'decided_at' => null,
        ];
    }
}
