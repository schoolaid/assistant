<?php

namespace SchoolAid\Assistant\Models;

use SchoolAid\Assistant\Models\Concerns\HasAssistantTable;
use SchoolAid\Assistant\Models\Concerns\OnSharedConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Un límite del asistente en la base central. En la fila general (`tenant_id` null), `value` es el valor por omisión
 * y `max` el techo general; en la de un market, `value` es lo que eligió el market, `max` el techo de central para ese
 * market y `locked`/`locked_value` el bloqueo de central. Ver {@see \SchoolAid\Assistant\Services\Settings\AssistantSettings}.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string $limit_key
 * @property string|null $value
 * @property string|null $max
 * @property bool $locked
 * @property string|null $locked_value
 */
class AssistantLimit extends Model
{
    use OnSharedConnection, HasAssistantTable;

    public const TABLE = 'limits';

    protected $fillable = ['tenant_id', 'limit_key', 'value', 'max', 'locked', 'locked_value'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'max' => 'decimal:4',
            'locked' => 'boolean',
            'locked_value' => 'decimal:4',
        ];
    }
}
