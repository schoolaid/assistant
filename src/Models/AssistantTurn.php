<?php

namespace SchoolAid\Assistant\Models;

use SchoolAid\Assistant\Models\Concerns\HasAssistantTable;
use SchoolAid\Assistant\Models\Concerns\InWorkspace;
use SchoolAid\Assistant\Enums\AssistantTurnStatusEnum;
use Carbon\CarbonImmutable;
use SchoolAid\Assistant\Database\Factories\AssistantTurnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $conversation_id
 * @property string $run_id
 * @property AssistantTurnStatusEnum $status
 * @property array<string, mixed>|null $panel_context
 * @property int $iterations
 * @property string|null $error
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $step_started_at
 * @property int $resumes
 */
class AssistantTurn extends Model
{
    use HasFactory, HasAssistantTable, InWorkspace;

    public const TABLE = 'turns';

    protected $fillable = [
        'conversation_id', 'run_id', 'status', 'panel_context', 'iterations', 'error', 'started_at', 'finished_at',
        'step_started_at', 'resumes',
    ];

    /**
     * Cada turno toca la conversación: la poda mide la inactividad con su `updated_at`.
     *
     * @var list<string>
     */
    protected $touches = ['conversation'];

    protected static function newFactory(): AssistantTurnFactory
    {
        return AssistantTurnFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => AssistantTurnStatusEnum::class,
            'panel_context' => 'array',
            'iterations' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'step_started_at' => 'datetime',
            'resumes' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'turn_id');
    }

    public function confirmation(): HasOne
    {
        return $this->hasOne(AssistantConfirmation::class, 'turn_id');
    }
}
