<?php

namespace SchoolAid\Assistant\Console;

use SchoolAid\Assistant\Support\PanelUsers;
use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Contracts\WorkspaceNotFound;
use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Models\AssistantMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Las respuestas que el staff marcó «no útil», por market: fecha, usuario, pedido, respuesta y comentario. Así lee
 * SAID la retroalimentación del asistente (spec, «Visibilidad y retroalimentación»): el pedido puede traer datos
 * personales, por eso se queda en el tenant y no va a la central. Cada caso es candidato al eval de tareas.
 */
class AssistantFeedbackCommand extends Command
{
    private const MAX_CHARS = 160;

    protected $signature = 'assistant:feedback
                            {--since=7 : Días hacia atrás}
                            {--tenant= : Tenant a revisar (omitir para revisar todos)}';

    protected $description = 'Lista por market las respuestas del asistente que el staff marcó «no útil»';

    public function handle(): int
    {
        $days = (int) $this->option('since');

        if ($days < 1) {
            $this->error('--since debe ser al menos 1.');

            return self::FAILURE;
        }

        $tenantId = $this->option('tenant');
        $workspace = app(Workspace::class);

        if ($tenantId !== null) {
            try {
                $workspace->run((string) $tenantId, fn () => $this->printFeedback((string) $tenantId, $days));
            } catch (WorkspaceNotFound) {
                $this->error("No existe el tenant «{$tenantId}».");

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        foreach ($workspace->ids() as $id) {
            try {
                $workspace->run($id, fn () => $this->printFeedback($id, $days));
            } catch (Throwable $e) {
                $this->error("Tenant {$id}: no se pudo leer la retroalimentación: {$e->getMessage()}");
                Log::error("assistant:feedback falló para el tenant {$id}", ['exception' => $e]);
            }
        }

        return self::SUCCESS;
    }

    private function printFeedback(string $tenantId, int $days): void
    {
        $unhelpful = AssistantMessage::query()
            ->with('conversation:id,user_id')
            ->where('role', 'assistant')
            ->where('helpful', false)
            ->where('updated_at', '>=', now()->subDays($days))
            ->orderBy('id')
            ->get();

        if ($unhelpful->isEmpty()) {
            return;
        }

        $users = PanelUsers::query()
            ->whereIn('id', $unhelpful->pluck('conversation.user_id')->filter()->unique())
            ->pluck('name', 'id');
        $requests = $this->requestsByConversation($unhelpful->pluck('conversation_id')->unique());

        $this->line("Market {$tenantId} (".(app(Workspace::class)->name() ?? $tenantId)."): {$unhelpful->count()} respuesta(s) «no útil»");
        $this->table(
            ['Fecha', 'Usuario', 'Pedido', 'Respuesta', 'Comentario'],
            $unhelpful->map(fn (AssistantMessage $answer): array => [
                $answer->updated_at?->format('d-m-Y H:i'),
                $users[$answer->conversation?->user_id] ?? '#'.$answer->conversation?->user_id,
                $this->short($this->requestBefore($requests->get($answer->conversation_id, collect()), $answer->id)),
                $this->short($answer->display_text),
                $this->short($answer->feedback_comment),
            ])->all(),
        );
    }

    /**
     * Los mensajes escritos por la persona en esas conversaciones, en orden.
     *
     * @param  Collection<int, int>  $conversationIds
     * @return Collection<int, Collection<int, AssistantMessage>>
     */
    private function requestsByConversation(Collection $conversationIds): Collection
    {
        return AssistantMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('role', 'user')
            ->where('kind', AssistantMessageKindEnum::Text->value)
            ->orderBy('id')
            ->get(['id', 'conversation_id', 'display_text'])
            ->groupBy('conversation_id');
    }

    /**
     * El último pedido de la persona antes de la respuesta calificada.
     *
     * @param  Collection<int, AssistantMessage>  $requests
     */
    private function requestBefore(Collection $requests, int $answerId): ?string
    {
        return $requests->last(fn (AssistantMessage $request): bool => $request->id < $answerId)?->display_text;
    }

    private function short(?string $text): string
    {
        return Str::limit(trim((string) $text), self::MAX_CHARS);
    }
}
