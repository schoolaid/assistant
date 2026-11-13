<?php

namespace SchoolAid\Assistant\Jobs\Concerns;

use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Jobs\Middleware\EnterWorkspace;

/**
 * El job guarda el espacio de trabajo donde se despachó y vuelve a entrar a él al correr
 * ({@see EnterWorkspace}). Un job serializado antes de este trait llega con `null` y corre donde
 * lo deje la cola.
 */
trait RemembersWorkspace
{
    public ?string $workspaceId = null;

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new EnterWorkspace($this->workspaceId)];
    }

    private function rememberWorkspace(): void
    {
        $this->workspaceId = app(Workspace::class)->id();
    }
}
