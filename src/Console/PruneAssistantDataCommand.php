<?php

namespace SchoolAid\Assistant\Console;

use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Contracts\WorkspaceNotFound;
use SchoolAid\Assistant\Enums\AssistantExportStatusEnum;
use SchoolAid\Assistant\Models\AssistantExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Services\AssistantSchemaCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneAssistantDataCommand extends Command
{
    private const DEFAULT_RETENTION_DAYS = 180;

    protected $signature = 'assistant:prune
                            {--days=180 : Días sin actividad tras los que se borra una conversación}
                            {--tenant= : Tenant a procesar (omitir para procesar todos)}';

    protected $description = 'Borra las conversaciones del asistente sin actividad en la ventana de retención (y en cascada turnos, mensajes y confirmaciones)';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: self::DEFAULT_RETENTION_DAYS);

        if ($days < 1) {
            $this->error('--days debe ser al menos 1.');

            return self::FAILURE;
        }

        $tenantId = $this->option('tenant');
        $workspace = app(Workspace::class);

        if ($tenantId !== null) {
            try {
                $workspace->run((string) $tenantId, fn () => $this->prune($days, "tenant {$tenantId}"));
            } catch (WorkspaceNotFound) {
                $this->error("No existe el tenant «{$tenantId}».");

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        foreach ($workspace->ids() as $id) {
            try {
                $workspace->run($id, fn () => $this->prune($days, "tenant {$id}"));
            } catch (\Throwable $e) {
                $this->error("Tenant {$id}: la poda falló: {$e->getMessage()}");
                Log::error("assistant:prune falló para el tenant {$id}", ['exception' => $e]);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Se mide la inactividad con `updated_at` de la conversación (cada mensaje y turno la tocan) y se
     * usa `forceDelete()` para que las cascadas de las FK borren turnos, mensajes y confirmaciones.
     * Un tenant sin las tablas del asistente (antes del Job de migraciones) se salta sin error.
     */
    private function prune(int $days, string $label): void
    {
        if (! app(AssistantSchemaCheck::class)->tenantHasTables()) {
            $this->line("Asistente en {$label}: sin las tablas del asistente (falta el Job de migraciones); se omite.");

            return;
        }

        $this->pruneExports($label);

        $deleted = 0;

        AssistantConversation::withTrashed()
            ->where('updated_at', '<', now()->subDays($days))
            ->each(function (AssistantConversation $conversation) use (&$deleted): void {
                $this->deleteExportFiles(AssistantExport::query()->where('conversation_id', $conversation->id)->whereNotNull('path'));
                $conversation->forceDelete();
                $deleted++;
            });

        $this->info("Conversaciones del asistente podadas en {$label}: {$deleted}.");
    }

    /**
     * Los archivos de `export-file` vencidos (`assistant.exports.ttl_days`): se borra el archivo del disco y la fila
     * queda `expired`, para que la tarjeta del chat diga que venció. Los de una conversación que se poda se borran antes
     * de su cascada, para no dejar archivos huérfanos.
     */
    private function pruneExports(string $label): void
    {
        if (! Schema::hasTable('assistant_exports')) {
            return;
        }

        $expired = $this->deleteExportFiles(
            AssistantExport::query()->where('status', AssistantExportStatusEnum::Ready->value)->where('expires_at', '<', now()),
            markExpired: true,
        );

        $this->info("Archivos del asistente vencidos en {$label}: {$expired}.");
    }

    /**
     * @param  Builder<AssistantExport>  $exports
     */
    private function deleteExportFiles(Builder $exports, bool $markExpired = false): int
    {
        if (! Schema::hasTable('assistant_exports')) {
            return 0;
        }

        $count = 0;
        $disk = Storage::disk((string) config('assistant.exports.disk'));

        $exports->each(function (AssistantExport $export) use ($disk, $markExpired, &$count): void {
            if ($export->path !== null) {
                $disk->delete($export->path);
            }

            if ($markExpired) {
                $export->forceFill(['status' => AssistantExportStatusEnum::Expired, 'path' => null])->save();
            }

            $count++;
        });

        return $count;
    }
}
