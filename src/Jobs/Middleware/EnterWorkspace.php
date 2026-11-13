<?php

namespace SchoolAid\Assistant\Jobs\Middleware;

use SchoolAid\Assistant\Contracts\Workspace;
use Closure;

/**
 * Corre el job dentro del espacio de trabajo donde se despachó. Si la cola ya lo dejó en ese
 * espacio (MarketAid: stancl/tenancy lo restaura antes de este middleware), no hace nada.
 */
class EnterWorkspace
{
    public function __construct(private readonly ?string $workspaceId) {}

    public function handle(object $job, Closure $next): void
    {
        $workspace = app(Workspace::class);

        if ($this->workspaceId === null || $workspace->id() === $this->workspaceId) {
            $next($job);

            return;
        }

        $workspace->run($this->workspaceId, fn () => $next($job));
    }
}
