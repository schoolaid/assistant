<?php

namespace SchoolAid\Assistant\Services\Undo;

use SchoolAid\Assistant\Contracts\WritePreviews;
use SchoolAid\Assistant\Enums\AssistantConfirmationStatusEnum;
use SchoolAid\Assistant\Models\AssistantConfirmation;
use SchoolAid\Assistant\Services\Tools\ToolBridge;
use Carbon\Carbon;

/**
 * Después de confirmar una escritura del grupo 1, deja lista la vista previa de su inversa: una confirmación
 * `pending` que vence en `assistant.undo_minutes` y que se confirma con el mismo endpoint de siempre. La vista previa
 * se pide al puente sin el modelo y dentro del contexto del turno (quien llama ya está en {@see \SchoolAid\Assistant\Services\Tools\PanelContextScope::run()}),
 * así la huella de la tool se toma justo después de la escritura: si el registro cambia por otro camino, la tool
 * rechaza el deshacer y no pisa nada.
 */
class UndoOffer
{
    public function __construct(
        private readonly ToolBridge $tools,
        private readonly WritePreviews $previews,
    ) {
    }

    public function offer(AssistantConfirmation $done, string $runId): ?AssistantConfirmation
    {
        $planner = $this->planner($done->tool);
        $call = $planner?->inverse($done);

        if ($planner === null || $call === null) {
            return null;
        }

        $preview = $this->tools->call($call->tool, $call->arguments, $runId, fromModel: false);

        if ($preview->isError || ! $preview->isPreview || $preview->confirmationToken === null) {
            return null;
        }

        $inversePreview = is_array($preview->payload['preview'] ?? null) ? $preview->payload['preview'] : [];

        if (! $planner->accepts($done, $inversePreview)) {
            $this->previews->discard($preview->confirmationToken);

            return null;
        }

        $summary = $preview->summary !== null && trim($preview->summary) !== '' ? $preview->summary : 'Deshacer '.$done->tool;
        $windowEnds = now()->addMinutes((int) config('assistant.undo_minutes', 5));
        $expiresAt = $preview->expiresAt !== null
            ? Carbon::parse($preview->expiresAt)->setTimezone((string) config('app.timezone'))
            : $windowEnds;

        return AssistantConfirmation::query()->create([
            'turn_id' => $done->turn_id,
            'conversation_id' => $done->conversation_id,
            'message_id' => $done->message_id,
            'tool' => $call->tool,
            'arguments' => $call->arguments,
            'summary' => $summary,
            'preview' => $inversePreview,
            'token' => $preview->confirmationToken,
            'expires_at' => $expiresAt->lessThan($windowEnds) ? $expiresAt : $windowEnds,
            'status' => AssistantConfirmationStatusEnum::Pending,
            'undoes_confirmation_id' => $done->id,
        ]);
    }

    private function planner(string $tool): ?UndoPlanner
    {
        $class = config("assistant.undo.{$tool}");

        return is_string($class) && is_subclass_of($class, UndoPlanner::class) ? app($class) : null;
    }
}
