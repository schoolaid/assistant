<?php

namespace SchoolAid\Assistant\Tests\Fixtures;

use Closure;
use SchoolAid\Assistant\Contracts\Workspace;

/**
 * The workspace of a single-database app: an id held in memory, platform tables on the default connection.
 */
class FakeWorkspace implements Workspace
{
    public ?string $current = null;

    public function id(): ?string
    {
        return $this->current;
    }

    public function name(): ?string
    {
        return $this->current === null ? null : "School {$this->current}";
    }

    public function ids(): array
    {
        return ['1', '2'];
    }

    public function run(string $id, Closure $callback): mixed
    {
        $previous = $this->current;
        $this->current = $id;

        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }

    public function sharedConnection(): ?string
    {
        return null;
    }
}
