<?php

namespace SchoolAid\Assistant\Models;

use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Database\Factories\AssistantMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int|null $turn_id
 * @property string $role
 * @property AssistantMessageKindEnum $kind
 * @property array<int|string, mixed> $content
 * @property string|null $display_text
 * @property array{question: string, options: list<array{id: string, label: string, detail: string|null, selectable: bool}>}|null $choices solo en los mensajes `choices`
 * @property string|null $model
 * @property string|null $stop_reason `stop_reason` de la Messages API en las respuestas guardadas; null en lo que escribe el backend
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property bool|null $helpful
 * @property string|null $feedback_comment
 */
class AssistantMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id', 'turn_id', 'role', 'kind', 'content', 'display_text', 'choices', 'payload', 'model', 'stop_reason',
        'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'helpful', 'feedback_comment',
    ];

    /**
     * @var list<string>
     */
    protected $touches = ['conversation'];

    protected static function newFactory(): AssistantMessageFactory
    {
        return AssistantMessageFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => AssistantMessageKindEnum::class,
            'content' => 'array',
            'choices' => 'array',
            'payload' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'helpful' => 'boolean',
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
     * La confirmación de un mensaje `confirmation` (la tarjeta de la vista previa). Nunca su deshacer: comparte la
     * tarjeta (`message_id`) con la escritura y va en su `undoOffer`.
     */
    public function confirmation(): HasOne
    {
        return $this->hasOne(AssistantConfirmation::class, 'message_id')->whereNull('undoes_confirmation_id');
    }

    /**
     * Lo que el panel muestra: ni los mensajes `internal` (resultados de tools, avisos al agente) ni las respuestas del
     * modelo sin texto (rondas que solo pidieron tools). El panel lee `display_text`, nunca `content`: un `error`
     * guarda ahí los bloques crudos de un rechazo.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleInPanel(Builder $query): void
    {
        $query->where('kind', '!=', AssistantMessageKindEnum::Internal->value)
            ->whereNot(fn (Builder $hidden) => $hidden
                ->where('role', 'assistant')
                ->where('kind', AssistantMessageKindEnum::Text->value)
                ->where(fn (Builder $empty) => $empty->whereNull('display_text')->orWhereRaw("TRIM(display_text) = ''")));
    }
}
