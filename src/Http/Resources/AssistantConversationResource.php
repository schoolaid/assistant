<?php

namespace SchoolAid\Assistant\Http\Resources;

use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Support\ConversationLimit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `active_turn`: `{id, status}` del turno más nuevo en curso (`queued` o `running`) o `null`: a cuál hacerle poll al reabrir.
 * `outcome`: `done` si alguna escritura de la conversación quedó hecha (confirmada y sin un deshacer confirmado), si no
 * `query` (solo lecturas, o vistas previas rechazadas, vencidas o fallidas).
 * `closed`: la conversación ya no admite otro turno, la misma regla del 409 `assistant_conversation_too_long`
 * ({@see ConversationLimit::reached()}): el panel ofrece una nueva. Una colección la resuelve para toda la página de una
 * vez ({@see self::newCollection()}); una conversación recién creada la trae dada ({@see self::withClosed()}).
 *
 * @mixin AssistantConversation
 */
class AssistantConversationResource extends JsonResource
{
    public const OUTCOME_DONE = 'done';

    public const OUTCOME_QUERY = 'query';

    private ?bool $closed = null;

    /**
     * @return array{id: int, status: string}|null
     */
    public static function activeTurnOf(AssistantConversation $conversation): ?array
    {
        $turn = $conversation->activeTurn;

        return $turn === null ? null : ['id' => $turn->id, 'status' => $turn->status->value];
    }

    /**
     * `closed` de todas las conversaciones de la colección con las dos consultas de {@see ConversationLimit::reachedEach()}.
     *
     * @param  mixed  $resource
     */
    protected static function newCollection($resource): AnonymousResourceCollection
    {
        $collection = parent::newCollection($resource);
        $closed = app(ConversationLimit::class)->reachedEach(
            $collection->collection->map(fn (self $conversation): int => (int) $conversation->id)->values()->all(),
        );

        foreach ($collection->collection as $conversation) {
            $conversation->withClosed($closed[(int) $conversation->id]);
        }

        return $collection;
    }

    /**
     * `closed` ya resuelto: sin consultar {@see ConversationLimit}.
     */
    public function withClosed(bool $closed): static
    {
        $this->closed = $closed;

        return $this;
    }

    /**
     * @return array{id: int, title: string|null, outcome: string, closed: bool, active_turn: array{id: int, status: string}|null, created_at: string|null, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'outcome' => $this->resource->hasKeptWrite() ? self::OUTCOME_DONE : self::OUTCOME_QUERY,
            'closed' => $this->closed ?? app(ConversationLimit::class)->reached((int) $this->id),
            'active_turn' => self::activeTurnOf($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
