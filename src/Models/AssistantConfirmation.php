<?php

namespace SchoolAid\Assistant\Models;

use SchoolAid\Assistant\Contracts\WritePreviews;
use SchoolAid\Assistant\Enums\AssistantConfirmationStatusEnum;
use Carbon\CarbonImmutable;
use SchoolAid\Assistant\Database\Factories\AssistantConfirmationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $turn_id
 * @property int $conversation_id
 * @property int $message_id
 * @property string $tool
 * @property array<string, mixed> $arguments
 * @property string $summary
 * @property array<string, mixed> $preview
 * @property string $token
 * @property CarbonImmutable $expires_at
 * @property AssistantConfirmationStatusEnum $status
 * @property array<string, mixed>|null $result
 * @property CarbonImmutable|null $decided_at cuándo se decidió; con `status` todavía `pending`, una request la está ejecutando (ver {@see \SchoolAid\Assistant\Services\ConfirmationExecutor})
 * @property int|null $undoes_confirmation_id si es el deshacer de otra confirmación, la que deshace
 */
class AssistantConfirmation extends Model
{
    use HasFactory;

    protected $fillable = [
        'turn_id', 'conversation_id', 'message_id', 'tool', 'arguments', 'summary', 'preview',
        'token', 'expires_at', 'status', 'result', 'decided_at', 'undoes_confirmation_id',
    ];

    protected static function newFactory(): AssistantConfirmationFactory
    {
        return AssistantConfirmationFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'preview' => 'array',
            'result' => 'array',
            'status' => AssistantConfirmationStatusEnum::class,
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function turn(): BelongsTo
    {
        return $this->belongsTo(AssistantTurn::class, 'turn_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AssistantMessage::class, 'message_id');
    }

    /**
     * La confirmación original, si esta es un deshacer.
     */
    public function undoes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'undoes_confirmation_id');
    }

    /**
     * La confirmación que deshace a esta, si se ofreció.
     */
    public function undoOffer(): HasOne
    {
        return $this->hasOne(self::class, 'undoes_confirmation_id');
    }

    /**
     * Las escrituras que quedaron hechas: confirmadas, que no son un deshacer y sin un deshacer confirmado.
     *
     * @param  Builder<self>  $query
     */
    public function scopeKept(Builder $query): void
    {
        $query->where('status', AssistantConfirmationStatusEnum::Confirmed->value)
            ->whereNull('undoes_confirmation_id')
            ->whereDoesntHave('undoOffer', fn (Builder $undo) => $undo->where('status', AssistantConfirmationStatusEnum::Confirmed->value));
    }

    /**
     * Una request la está ejecutando ahora: sigue `pending` con un reclamo (`decided_at`) de hace menos del candado del
     * token ({@see WritePreviews::lockSeconds()}, el tope de una request). Un reclamo más viejo murió a medias.
     */
    public function isExecuting(): bool
    {
        return $this->status === AssistantConfirmationStatusEnum::Pending
            && $this->decided_at !== null
            && $this->decided_at->greaterThan(now()->subSeconds(app(WritePreviews::class)->lockSeconds()));
    }

    /**
     * Las que una request está ejecutando ahora ({@see self::isExecuting()}), deshacer incluido. Con una así en la
     * conversación, ni el envío de un mensaje ni Confirmar / Rechazar otra arrancan: abrirían un segundo turno vivo.
     *
     * @param  Builder<self>  $query
     */
    public function scopeExecuting(Builder $query): void
    {
        $query->where('status', AssistantConfirmationStatusEnum::Pending->value)
            ->where('decided_at', '>', now()->subSeconds(app(WritePreviews::class)->lockSeconds()));
    }
}
