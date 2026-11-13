<?php

namespace SchoolAid\Assistant\Enums;

/**
 * Estado de un archivo de `export-file`: `pending` mientras BuildAssistantExport lo arma, `ready` para bajarlo,
 * `failed` con el motivo en `error` y `expired` cuando `assistant:prune` borró el archivo vencido.
 */
enum AssistantExportStatusEnum: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';
}
