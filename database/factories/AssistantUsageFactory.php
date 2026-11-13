<?php

namespace SchoolAid\Assistant\Database\Factories;

use SchoolAid\Assistant\Models\AssistantUsage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AssistantUsage>
 */
class AssistantUsageFactory extends Factory
{
    protected $model = AssistantUsage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => 'tenant-'.Str::random(8),
            'period' => now()->format('Y-m'),
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'cost_usd' => 0,
            'warned_at' => null,
        ];
    }
}
