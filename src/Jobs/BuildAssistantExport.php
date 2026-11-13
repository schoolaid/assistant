<?php

namespace SchoolAid\Assistant\Jobs;

use SchoolAid\Assistant\Contracts\PanelScope;
use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Enums\AssistantExportStatusEnum;
use SchoolAid\Assistant\Models\AssistantExport;
use SchoolAid\Assistant\Services\Exports\AssistantExportRows;
use SchoolAid\Assistant\Services\Exports\AssistantExportSheet;
use SchoolAid\Assistant\Services\Tools\ToolBridge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Arma el archivo de un `export-file` ya aceptado (`pending`).
 *
 * 1. Pagina la misma lectura por el puente, dentro de {@see PanelContextScope} con el contexto del turno: mismo
 *    usuario, misma sucursal y mismos permisos que cuando la persona lo pidió.
 * 2. Escribe el xlsx en el disco privado (`assistant.exports.disk`).
 * 3. Lo deja `ready` con su vencimiento (`assistant.exports.ttl_days`).
 *
 * Si algo falla, queda `failed` con un motivo para la persona; el detalle va a `report()`. Va en la cola del
 * asistente, sin reintentos: pedirlo otra vez es un mensaje.
 */
class BuildAssistantExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const FAILED_MESSAGE = 'No se pudo generar el archivo. Pídelo de nuevo en un momento.';

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(public readonly string $exportId)
    {
        $this->onConnection(RunAssistantStep::CONNECTION);
        $this->onQueue(RunAssistantStep::QUEUE);
    }

    public function handle(PanelScope $scope, ToolBridge $bridge, Workspace $workspace): void
    {
        $export = AssistantExport::query()->with('turn')->find($this->exportId);

        if ($export === null || $export->status !== AssistantExportStatusEnum::Pending) {
            return;
        }

        try {
            $rows = $scope->run(
                $scope->restore((array) $export->turn->panel_context),
                fn (): array => AssistantExportRows::collect(
                    $bridge,
                    $export->source,
                    (string) $export->turn->run_id,
                    (int) config('assistant.exports.page_size'),
                    (int) config("assistant.exports.max_rows.{$export->format}"),
                ),
            );

            $path = trim((string) config('assistant.exports.directory'), '/').'/'.$workspace->id()."/{$export->id}.{$export->format}";
            Excel::store(new AssistantExportSheet($export->title, $rows, $export->columns), $path, (string) config('assistant.exports.disk'));

            $export->forceFill([
                'status' => AssistantExportStatusEnum::Ready,
                'rows' => count($rows),
                'path' => $path,
                'error' => null,
                'expires_at' => now()->addDays((int) config('assistant.exports.ttl_days')),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);
            $export->forceFill(['status' => AssistantExportStatusEnum::Failed, 'error' => self::FAILED_MESSAGE])->save();
        }
    }
}
