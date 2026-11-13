<?php

namespace SchoolAid\Assistant\Http\Resources;

use SchoolAid\Assistant\Enums\AssistantConfirmationStatusEnum;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Support\AssistantTurnSteps;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lo que el panel lee al hacer poll de un turno: su estado, sus mensajes visibles, la confirmación pendiente (la
 * tarjeta con Confirmar / Cambiar algo / Cancelar) y los pasos ya hechos. Lee los mensajes y la confirmación al
 * serializar: es la misma forma en el 202 del envío, en el poll y en el 202 de Confirmar / Rechazar.
 *
 * Un deshacer pendiente nunca es la confirmación del turno: comparte turno y tarjeta con su escritura y el panel lo
 * ofrece en el `undo` de esa tarjeta ({@see AssistantConfirmationResource}), cargado de una vez para todos los mensajes.
 *
 * @mixin AssistantTurn
 */
class AssistantTurnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $turn = $this->resource;
        $messages = $turn->messages()->visibleInPanel()->with('confirmation.undoOffer')->orderBy('id')->get();
        $pending = $turn->confirmation()
            ->whereNull('undoes_confirmation_id')
            ->where('status', AssistantConfirmationStatusEnum::Pending->value)
            ->first();

        return [
            'id' => $turn->id,
            'conversation_id' => $turn->conversation_id,
            'status' => $turn->status->value,
            'created_at' => $turn->created_at?->toIso8601String(),
            'started_at' => $turn->started_at?->toIso8601String(),
            'finished_at' => $turn->finished_at?->toIso8601String(),
            'messages' => AssistantMessageResource::collection($messages),
            'confirmation' => $pending === null ? null : new AssistantConfirmationResource($pending),
            'steps' => app(AssistantTurnSteps::class)->for($turn),
        ];
    }
}
