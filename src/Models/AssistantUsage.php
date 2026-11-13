<?php

namespace SchoolAid\Assistant\Models;

use SchoolAid\Assistant\Models\Concerns\HasAssistantTable;
use SchoolAid\Assistant\Models\Concerns\OnSharedConnection;
use Carbon\CarbonImmutable;
use SchoolAid\Assistant\Database\Factories\AssistantUsageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Consumo mensual del asistente por market, en la base central.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $period YYYY-MM
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property string $cost_usd
 * @property CarbonImmutable|null $warned_at
 */
class AssistantUsage extends Model
{
    use OnSharedConnection, HasFactory, HasAssistantTable;

    public const TABLE = 'usage';

    protected $fillable = [
        'tenant_id', 'period', 'input_tokens', 'output_tokens', 'cache_read_tokens',
        'cache_write_tokens', 'cost_usd', 'warned_at',
    ];

    protected static function newFactory(): AssistantUsageFactory
    {
        return AssistantUsageFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'cost_usd' => 'decimal:4',
            'warned_at' => 'datetime',
        ];
    }
}
