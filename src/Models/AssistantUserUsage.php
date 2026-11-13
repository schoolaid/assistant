<?php

namespace SchoolAid\Assistant\Models;

use SchoolAid\Assistant\Models\Concerns\OnSharedConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Consumo mensual del asistente por usuario del panel, en la base central. `user_id` es el id en la base del tenant:
 * siempre va con `tenant_id`.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $user_id
 * @property string $period YYYY-MM
 * @property int $responses
 * @property string $cost_usd
 */
class AssistantUserUsage extends Model
{
    use OnSharedConnection;

    protected $table = 'assistant_user_usage';

    protected $fillable = ['tenant_id', 'user_id', 'period', 'responses', 'cost_usd'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'responses' => 'integer',
            'cost_usd' => 'decimal:4',
        ];
    }
}
