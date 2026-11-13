<?php

namespace SchoolAid\Assistant\Models;

use SchoolAid\Assistant\Enums\AssistantExportStatusEnum;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un archivo que el asistente generó con `export-file` (ver {@see \SchoolAid\Assistant\Services\Exports\AssistantExportRequester}).
 *
 * @property string $id
 * @property int $conversation_id
 * @property int $turn_id
 * @property int $user_id
 * @property string $format
 * @property string $title
 * @property array{tool: string, arguments: array<string, mixed>} $source
 * @property list<array{field: string, label: string}>|null $columns
 * @property int|null $rows
 * @property AssistantExportStatusEnum $status
 * @property string|null $error
 * @property string|null $path
 * @property \Illuminate\Support\Carbon|null $expires_at
 */
class AssistantExport extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'conversation_id', 'turn_id', 'user_id', 'format', 'title', 'source', 'columns', 'rows', 'status',
        'error', 'path', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => 'array',
            'columns' => 'array',
            'rows' => 'integer',
            'status' => AssistantExportStatusEnum::class,
            'expires_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    public function turn(): BelongsTo
    {
        return $this->belongsTo(AssistantTurn::class, 'turn_id');
    }

    /**
     * Vencido aunque `assistant:prune` todavía no haya borrado el archivo.
     */
    public function isExpired(): bool
    {
        return $this->status === AssistantExportStatusEnum::Expired
            || ($this->expires_at !== null && $this->expires_at->isPast());
    }

    /**
     * El nombre con que se baja: el título sin caracteres que rompan un nombre de archivo, más la extensión.
     */
    public function filename(): string
    {
        $base = trim((string) preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $this->title));

        return Str::limit($base !== '' ? $base : 'Exportación', 120, '').'.'.$this->format;
    }
}
