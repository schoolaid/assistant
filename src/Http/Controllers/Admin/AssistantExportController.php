<?php

namespace SchoolAid\Assistant\Http\Controllers\Admin;

use SchoolAid\Assistant\Support\AssistantNaming;
use SchoolAid\Assistant\Http\Controllers\AssistantController;
use SchoolAid\Assistant\Enums\AssistantExportStatusEnum;
use SchoolAid\Assistant\Http\Resources\AssistantExportResource;
use SchoolAid\Assistant\Models\AssistantExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Los archivos de `export-file`. Solo los ve y los baja quien los pidió (AssistantConversationPolicy, por su
 * conversación). Se bajan con la sesión del panel, nunca con un enlace público.
 */
class AssistantExportController extends AssistantController
{
    /**
     * El estado del archivo: el panel lo consulta mientras está `pending`.
     */
    public function show(AssistantExport $export): AssistantExportResource
    {
        $this->authorize('view', $export);

        return new AssistantExportResource($export);
    }

    /**
     * El archivo. 410 `assistant_export_expired` si venció y 409 `assistant_export_not_ready` si todavía se arma o falló.
     */
    public function download(AssistantExport $export): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $export);

        if ($export->isExpired()) {
            return response()->json(['message' => 'El archivo venció: pídelo de nuevo al asistente.', 'code' => 'assistant_export_expired'], 410);
        }

        if ($export->status !== AssistantExportStatusEnum::Ready || $export->path === null) {
            return response()->json(['message' => 'El archivo todavía no está listo.', 'code' => 'assistant_export_not_ready'], 409);
        }

        return Storage::disk((string) AssistantNaming::config('exports.disk'))->download($export->path, $export->filename());
    }
}
