<?php

namespace SchoolAid\Assistant\Models;

use SchoolAid\Assistant\Models\Concerns\OnSharedConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que central decide de un market fuera de los límites: si puede traer su propia llave. Sin fila, no puede.
 *
 * @property int $id
 * @property string $tenant_id
 * @property bool $own_key_allowed
 */
class AssistantTenantSetting extends Model
{
    use OnSharedConnection;

    protected $table = 'assistant_tenant_settings';

    protected $fillable = ['tenant_id', 'own_key_allowed'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['own_key_allowed' => 'boolean'];
    }
}
