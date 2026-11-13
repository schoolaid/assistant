<?php

namespace SchoolAid\Assistant\Models\Scopes;

use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Support\AssistantNaming;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Para las apps que guardan todos los espacios de trabajo en una sola base: limita cada consulta
 * a las filas del espacio en curso, por la columna de `workspace_column`.
 *
 * - Sin columna configurada (una base por espacio, como MarketAid) no hace nada.
 * - Con columna y fuera de un espacio no devuelve filas: un comando de la plataforma que
 *   necesite leer todos lo pide con `withoutGlobalScope(WorkspaceScope::class)`.
 */
class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $column = AssistantNaming::config('workspace_column');

        if ($column === null) {
            return;
        }

        $workspaceId = app(Workspace::class)->id();

        if ($workspaceId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn($column), $workspaceId);
    }
}
