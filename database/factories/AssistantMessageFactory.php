<?php

namespace SchoolAid\Assistant\Database\Factories;

use SchoolAid\Assistant\Models\AssistantMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Models\AssistantConversation;

/**
 * @extends Factory<AssistantMessage>
 */
class AssistantMessageFactory extends Factory
{
    protected $model = AssistantMessage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conversation_id' => AssistantConversation::factory(),
            'turn_id' => null,
            'role' => 'user',
            'kind' => AssistantMessageKindEnum::Text,
            'content' => [['type' => 'text', 'text' => $this->faker->sentence()]],
            'display_text' => $this->faker->sentence(),
            'model' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'helpful' => null,
            'feedback_comment' => null,
        ];
    }
}
