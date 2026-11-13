<?php

namespace SchoolAid\Assistant\Models;

use SchoolAid\Assistant\Models\Concerns\HasAssistantTable;
use SchoolAid\Assistant\Enums\AssistantApiKeyStatusEnum;
use SchoolAid\Assistant\Models\Concerns\OnSharedConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Llave de Anthropic del asistente, en la base central: la general (`tenant_id` null) o la propia de un market. La llave
 * va cifrada y nunca sale en una respuesta: solo `last4`, el estado y la última verificación.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string $api_key
 * @property string $last4
 * @property AssistantApiKeyStatusEnum $status
 * @property CarbonImmutable|null $verified_at
 * @property string|null $last_error
 * @property string|null $updated_by
 */
class AssistantApiKey extends Model
{
    use OnSharedConnection, HasAssistantTable;

    public const TABLE = 'api_keys';

    protected $fillable = ['tenant_id', 'api_key', 'last4', 'status', 'verified_at', 'last_error', 'updated_by'];

    protected $hidden = ['api_key'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'status' => AssistantApiKeyStatusEnum::class,
            'verified_at' => 'datetime',
        ];
    }
}
