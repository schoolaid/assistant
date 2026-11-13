<?php

namespace SchoolAid\Assistant\Console;

use SchoolAid\Assistant\Contracts\AccessGate;
use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Contracts\WorkspaceNotFound;
use SchoolAid\Assistant\Contracts\WritePreviews;
use SchoolAid\Assistant\Enums\AssistantConfirmationStatusEnum;
use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Enums\AssistantTurnStatusEnum;
use SchoolAid\Assistant\Jobs\RunAssistantStep;
use SchoolAid\Assistant\Models\AssistantConfirmation;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Services\Agent\AssistantTurnRunner;
use SchoolAid\Assistant\Services\AssistantMessageSender;
use SchoolAid\Assistant\Services\AssistantSchemaCheck;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recolector de pasos vencidos (spec, «Ejecución por pasos»), cada minuto y solo en los markets con el asistente
 * encendido y sus tablas ya migradas (un market encendido antes del Job de migraciones se salta sin error). Corre en
 * primer plano y al final del schedule: el `schedule:run` del CronJob es el PID 1 y sale sin esperar a los hijos en
 * segundo plano, así que el kernel los mataría. Un turno está vencido si:
 * - está `running` con un paso reclamado hace más de {@see AssistantTurnRunner::STALE_STEP_SECONDS} (el worker murió:
 *   el Job tiene `timeout` 200 s), o
 * - está `queued` / `running` sin paso en curso y sin cambios hace más de {@see self::IDLE_SECONDS} (el Job del paso
 *   se perdió: nadie lo tomó o no se despachó el siguiente).
 *
 * La primera vez se retoma: `resumes` = 1, se suelta el reclamo y se despacha el paso otra vez (el paso arranca de lo
 * guardado). Si vuelve a vencer, el turno queda `failed` con un mensaje visible. Cada cambio va condicionado al estado
 * que se leyó: si el worker cierra el paso en ese momento, uno de los dos pierde y no se pisan (el runner revierte su
 * cierre si el reclamo ya no es suyo).
 *
 * También cierra los deshacer que murieron a medias ({@see self::closeInterruptedUndos()}).
 */
class ResumeStaleTurnsCommand extends Command
{
    public const INTERRUPTED_MESSAGE = 'Se interrumpió el pedido; vuelve a intentarlo.';

    public const IDLE_SECONDS = 120;

    protected $signature = 'assistant:resume-stale-turns
                            {--tenant= : Tenant a revisar (omitir para revisar todos)}';

    protected $description = 'Retoma una vez los pasos vencidos del asistente y cierra con un aviso los que vuelven a vencer';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $workspace = app(Workspace::class);

        if ($tenantId !== null) {
            try {
                $workspace->run((string) $tenantId, fn () => $this->sweepIfEnabled("tenant {$tenantId}"));
            } catch (WorkspaceNotFound) {
                $this->error("No existe el tenant «{$tenantId}».");

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        foreach ($workspace->ids() as $id) {
            try {
                $workspace->run($id, fn () => $this->sweepIfEnabled("tenant {$id}"));
            } catch (Throwable $e) {
                $this->error("Tenant {$id}: el recolector falló: {$e->getMessage()}");
                Log::error("assistant:resume-stale-turns falló para el tenant {$id}", ['exception' => $e]);
            }
        }

        return self::SUCCESS;
    }

    private function sweepIfEnabled(string $label): void
    {
        if (! app(AccessGate::class)->isEnabledForTenant()) {
            return;
        }

        if (! app(AssistantSchemaCheck::class)->tenantHasTables()) {
            $this->warn("Asistente en {$label}: encendido sin las tablas del asistente (falta el Job de migraciones); se omite.");

            return;
        }

        $resumed = 0;
        $failed = 0;

        foreach ($this->staleTurns() as $turn) {
            if ($turn->resumes < 1) {
                $resumed += $this->resume($turn) ? 1 : 0;
            } else {
                $failed += $this->closeAsInterrupted($turn) ? 1 : 0;
            }
        }

        if ($resumed + $failed > 0) {
            $this->info("Asistente en {$label}: {$resumed} turno(s) retomado(s), {$failed} cerrado(s) por vencidos.");
        }

        $undos = $this->closeInterruptedUndos();

        if ($undos > 0) {
            $this->info("Asistente en {$label}: {$undos} deshacer cerrado(s) por interrumpido(s).");
        }
    }

    /**
     * Un deshacer (confirmación con `undoes_confirmation_id`) reclamado hace más del candado del token
     * ({@see WritePreviews::lockSeconds()}, el tope de una request) en un turno `completed` murió a medias, y
     * nadie más lo cierra: el envío de mensajes solo cierra las vistas previas de los turnos que esperan su
     * confirmación. Queda `failed` con {@see AssistantMessageSender::INTERRUPTED_RESULT} y su `decided_at`, como cierra
     * el envío una confirmación interrumpida. Solo los deshacer (R-P2-41): las demás confirmaciones siguen como antes.
     *
     * Condicionado al reclamo que se leyó: si el Confirmar termina en ese momento, uno de los dos pierde.
     */
    private function closeInterruptedUndos(): int
    {
        $interrupted = AssistantConfirmation::query()
            ->whereNotNull('undoes_confirmation_id')
            ->where('status', AssistantConfirmationStatusEnum::Pending->value)
            ->where('decided_at', '<=', now()->subSeconds(app(WritePreviews::class)->lockSeconds()))
            ->whereHas('turn', fn (Builder $turn) => $turn->where('status', AssistantTurnStatusEnum::Completed->value))
            ->orderBy('id')
            ->get(['id', 'decided_at']);

        $closed = 0;

        foreach ($interrupted as $undo) {
            $closed += AssistantConfirmation::query()
                ->whereKey($undo->getKey())
                ->whereNotNull('undoes_confirmation_id')
                ->where('status', AssistantConfirmationStatusEnum::Pending->value)
                ->where('decided_at', $undo->decided_at)
                ->update([
                    'status' => AssistantConfirmationStatusEnum::Failed->value,
                    'result' => json_encode(AssistantMessageSender::INTERRUPTED_RESULT),
                ]);
        }

        return $closed;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, AssistantTurn>
     */
    private function staleTurns(): \Illuminate\Database\Eloquent\Collection
    {
        return AssistantTurn::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $deadStep) => $deadStep
                    ->where('status', AssistantTurnStatusEnum::Running->value)
                    ->where('step_started_at', '<=', now()->subSeconds(AssistantTurnRunner::STALE_STEP_SECONDS)))
                ->orWhere(fn (Builder $lostJob) => $lostJob
                    ->whereIn('status', [AssistantTurnStatusEnum::Queued->value, AssistantTurnStatusEnum::Running->value])
                    ->whereNull('step_started_at')
                    ->where('updated_at', '<=', now()->subSeconds(self::IDLE_SECONDS))))
            ->orderBy('id')
            ->get();
    }

    private function resume(AssistantTurn $turn): bool
    {
        $resumed = $this->unchanged($turn)
            ->where('resumes', 0)
            ->increment('resumes', 1, ['step_started_at' => null]);

        if ($resumed !== 1) {
            return false;
        }

        RunAssistantStep::dispatch($turn->id);

        return true;
    }

    /**
     * El turno y su mensaje visible en la misma transacción: quien hace poll ve `failed` con el aviso ya guardado.
     */
    private function closeAsInterrupted(AssistantTurn $turn): bool
    {
        return DB::transaction(function () use ($turn): bool {
            $failed = $this->unchanged($turn)->update([
                'status' => AssistantTurnStatusEnum::Failed->value,
                'finished_at' => now(),
                'step_started_at' => null,
                'error' => 'assistant:resume-stale-turns: el paso venció otra vez después de retomarlo.',
            ]);

            if ($failed !== 1) {
                return false;
            }

            AssistantMessage::query()->create([
                'conversation_id' => $turn->conversation_id,
                'turn_id' => $turn->id,
                'role' => 'assistant',
                'kind' => AssistantMessageKindEnum::Error,
                'content' => [['type' => 'text', 'text' => self::INTERRUPTED_MESSAGE]],
                'display_text' => self::INTERRUPTED_MESSAGE,
            ]);

            return true;
        });
    }

    /**
     * El turno, solo si sigue como se leyó (estado, reclamo y retomas).
     *
     * @return Builder<AssistantTurn>
     */
    private function unchanged(AssistantTurn $turn): Builder
    {
        $query = AssistantTurn::query()
            ->whereKey($turn->getKey())
            ->where('status', $turn->status->value)
            ->where('resumes', $turn->resumes);

        return $turn->step_started_at === null
            ? $query->whereNull('step_started_at')
            : $query->where('step_started_at', $turn->step_started_at);
    }
}
