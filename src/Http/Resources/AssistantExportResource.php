<?php

namespace SchoolAid\Assistant\Http\Resources;

use SchoolAid\Assistant\Enums\AssistantExportStatusEnum;
use SchoolAid\Assistant\Models\AssistantExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un archivo de `export-file` como lo muestra el panel: la tarjeta con su estado y el botón para descargar. Un archivo
 * vencido sale `expired` aunque `assistant:prune` todavía no lo haya borrado. `error` es copy para la persona.
 *
 * @mixin AssistantExport
 */
class AssistantExportResource extends JsonResource
{
    /**
     * @return array{id: string, format: string, title: string, filename: string, rows: int|null, status: string, error: string|null, expires_at: string|null}
     */
    public function toArray(Request $request): array
    {
        $status = $this->status === AssistantExportStatusEnum::Ready && $this->resource->isExpired()
            ? AssistantExportStatusEnum::Expired
            : $this->status;

        return [
            'id' => $this->id,
            'format' => $this->format,
            'title' => $this->title,
            'filename' => $this->resource->filename(),
            'rows' => $this->rows,
            'status' => $status->value,
            'error' => $status === AssistantExportStatusEnum::Failed ? $this->error : null,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
