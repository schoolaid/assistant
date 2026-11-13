<?php

namespace SchoolAid\Assistant\Models\Concerns;

use SchoolAid\Assistant\Contracts\Workspace;

/**
 * Para los modelos de la plataforma (llaves, límites, consumo): viven en la conexión que diga
 * {@see Workspace::sharedConnection()} y no en la del espacio de trabajo en curso.
 */
trait OnSharedConnection
{
    public function getConnectionName(): ?string
    {
        return app(Workspace::class)->sharedConnection();
    }
}
