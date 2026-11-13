<?php

namespace SchoolAid\Assistant\Models\Concerns;

use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Models\Scopes\WorkspaceScope;
use SchoolAid\Assistant\Support\AssistantNaming;
use Illuminate\Database\Eloquent\Model;

/**
 * Para los modelos que pertenecen a un espacio de trabajo (conversaciones, turnos, mensajes,
 * confirmaciones, archivos). Si la app configura `workspace_column`, cada fila guarda el espacio
 * donde se creó y las consultas solo ven las del espacio en curso ({@see WorkspaceScope}).
 */
trait InWorkspace
{
    public static function bootInWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function (Model $model): void {
            $column = AssistantNaming::config('workspace_column');

            if ($column !== null && $model->getAttribute($column) === null) {
                $model->setAttribute($column, app(Workspace::class)->id());
            }
        });
    }
}
