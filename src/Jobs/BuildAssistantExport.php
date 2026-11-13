<?php

namespace SchoolAid\Assistant\Jobs;

use SchoolAid\Assistant\Jobs\Concerns\RemembersWorkspace;
use SchoolAid\Assistant\Support\AssistantNaming;
use SchoolAid\Assistant\Contracts\PanelScope;
use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Enums\AssistantExportStatusEnum;
use SchoolAid\Assistant\Models\AssistantExport;
use SchoolAid\Assistant\Services\Agent\ExportDocumentTool;
use SchoolAid\Assistant\Services\Exports\AssistantExportPdf;
use SchoolAid\Assistant\Services\Exports\AssistantExportRows;
use SchoolAid\Assistant\Services\Exports\AssistantExportSheet;
use SchoolAid\Assistant\Services\Tools\ToolBridge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Arma el archivo de un `export-file` o un `export-document` ya aceptado (`pending`).
 *
 * 1. `export-file`: pagina la misma lectura por el puente, dentro de {@see PanelContextScope} con el contexto del
 *    turno (mismo usuario, misma sucursal y mismos permisos que cuando la persona lo pidió), y arma el xlsx
 *    ({@see AssistantExportSheet}) o el pdf ({@see AssistantExportPdf::table()}). `export-document` no lee nada: pasa
 *    el markdown a PDF ({@see AssistantExportPdf::document()}).
 * 2. Escribe el archivo en el disco privado (`assistant.exports.disk`).
 * 3. Lo deja `ready` con su vencimiento (`assistant.exports.ttl_days`).
 *
 * Si algo falla, queda `failed` con un motivo para la persona; el detalle va a `report()`. Va en la cola del
 * asistente, sin reintentos: pedirlo otra vez es un mensaje.
 */
class BuildAssistantExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RemembersWorkspace, SerializesModels;

    public const FAILED_MESSAGE = 'No se pudo generar el archivo. Pídelo de nuevo en un momento.';

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(public readonly string $exportId)
    {
        $this->onConnection(RunAssistantStep::CONNECTION);
        $this->onQueue(RunAssistantStep::QUEUE);
        $this->rememberWorkspace();
    }

    public function handle(PanelScope $scope, ToolBridge $bridge, Workspace $workspace, AssistantExportPdf $pdf): void
    {
        $export = AssistantExport::query()->with('turn')->find($this->exportId);

        if ($export === null || $export->status !== AssistantExportStatusEnum::Pending) {
            return;
        }

        try {
            $path = trim((string) AssistantNaming::config('exports.directory'), '/').'/'.$workspace->id()."/{$export->id}.{$export->format}";
            $disk = (string) AssistantNaming::config('exports.disk');
            $rows = null;

            if (($export->source['tool'] ?? null) === ExportDocumentTool::NAME) {
                Storage::disk($disk)->put($path, $pdf->document($export));
            } else {
                $rows = $scope->run(
                    $scope->restore((array) $export->turn->panel_context),
                    fn (): array => AssistantExportRows::collect(
                        $bridge,
                        $export->source,
                        (string) $export->turn->run_id,
                        (int) AssistantNaming::config('exports.page_size'),
                        (int) AssistantNaming::config("exports.max_rows.{$export->format}"),
                    ),
                );

                if ($export->format === 'pdf') {
                    Storage::disk($disk)->put($path, $pdf->table($export, $rows));
                } else {
                    Excel::store(new AssistantExportSheet($export->title, $rows, $export->columns), $path, $disk);
                }
            }

            $export->forceFill([
                'status' => AssistantExportStatusEnum::Ready,
                'rows' => $rows !== null ? count($rows) : null,
                'path' => $path,
                'error' => null,
                'expires_at' => now()->addDays((int) AssistantNaming::config('exports.ttl_days')),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);
            $export->forceFill(['status' => AssistantExportStatusEnum::Failed, 'error' => self::FAILED_MESSAGE])->save();
        }
    }
}
