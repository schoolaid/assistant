<?php

namespace SchoolAid\Assistant\Models;

use SchoolAid\Assistant\Models\Concerns\HasAssistantTable;
use SchoolAid\Assistant\Models\Concerns\InWorkspace;
use SchoolAid\Assistant\Support\PanelUsers;
use SchoolAid\Assistant\Enums\AssistantTurnStatusEnum;
use Carbon\CarbonImmutable;
use SchoolAid\Assistant\Database\Factories\AssistantConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $title
 * @property string|null $system_prompt
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class AssistantConversation extends Model
{
    use HasFactory, SoftDeletes, HasAssistantTable, InWorkspace;

    public const TABLE = 'conversations';

    private const KEPT_WRITE = 'has_kept_write';

    protected $fillable = ['user_id', 'title', 'system_prompt'];

    protected static function newFactory(): AssistantConversationFactory
    {
        return AssistantConversationFactory::new();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id');
    }

    public function turns(): HasMany
    {
        return $this->hasMany(AssistantTurn::class, 'conversation_id');
    }

    /**
     * El turno más nuevo que sigue en curso (`queued` o `running`): lo que la página que reabre la conversación sondea.
     * `ofMany` para cargarlo con `with()` sin una consulta por conversación.
     *
     * @return HasOne<AssistantTurn>
     */
    public function activeTurn(): HasOne
    {
        return $this->hasOne(AssistantTurn::class, 'conversation_id')->ofMany(
            ['id' => 'max'],
            fn (Builder $turns) => $turns->whereIn('status', [AssistantTurnStatusEnum::Queued->value, AssistantTurnStatusEnum::Running->value]),
        );
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(AssistantConfirmation::class, 'conversation_id');
    }

    /**
     * Suma `has_kept_write` en la misma consulta: si alguna escritura de la conversación quedó hecha
     * ({@see AssistantConfirmation::scopeKept()}).
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithKeptWrite(Builder $query): void
    {
        $query->withExists(['confirmations as '.self::KEPT_WRITE => fn (Builder $confirmations) => $confirmations->kept()]);
    }

    /**
     * Si alguna escritura quedó hecha: lo que sumó {@see self::scopeWithKeptWrite()} o, sin él, una consulta.
     */
    public function hasKeptWrite(): bool
    {
        $loaded = $this->getAttribute(self::KEPT_WRITE);

        return $loaded === null ? $this->confirmations()->kept()->exists() : (bool) $loaded;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(PanelUsers::model());
    }
}
