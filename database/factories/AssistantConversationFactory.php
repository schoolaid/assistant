<?php

namespace SchoolAid\Assistant\Database\Factories;

use SchoolAid\Assistant\Models\AssistantConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssistantConversation>
 */
class AssistantConversationFactory extends Factory
{
    protected $model = AssistantConversation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => $this->faker->numberBetween(1, 100000),
            'title' => $this->faker->sentence(4),
        ];
    }
}
