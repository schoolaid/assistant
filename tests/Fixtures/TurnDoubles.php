<?php

namespace SchoolAid\Assistant\Tests\Fixtures;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Mcp\Server\Tool;
use SchoolAid\Assistant\Contracts\AccessGate;
use SchoolAid\Assistant\Contracts\BudgetAlerts;
use SchoolAid\Assistant\Contracts\Instructions;
use SchoolAid\Assistant\Contracts\PanelScope;
use SchoolAid\Assistant\Contracts\PanelSnapshot;
use SchoolAid\Assistant\Contracts\ToolSource;
use SchoolAid\Assistant\Contracts\WritePreviews;
use SchoolAid\Assistant\Services\Tools\ToolBridge;
use SchoolAid\Assistant\Services\Tools\ToolCallResult;

/*
| The least an app has to answer for a turn to run: doubles of the host contracts, bound by
| ScriptedTurn::bindTurnDoubles(). They say yes to everything and record what a test may assert.
*/

final class FakeSnapshot implements PanelSnapshot
{
    public function __construct(private readonly int $userId)
    {
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function toArray(): array
    {
        return ['user_id' => $this->userId];
    }
}

final class FakePanelScope implements PanelScope
{
    public function capture(Authenticatable $user): PanelSnapshot
    {
        return new FakeSnapshot((int) $user->getAuthIdentifier());
    }

    public function restore(array $data): PanelSnapshot
    {
        return new FakeSnapshot((int) ($data['user_id'] ?? 0));
    }

    public function run(PanelSnapshot $context, Closure $callback): mixed
    {
        return $callback();
    }

    public function isolateCall(Closure $call): mixed
    {
        return $call();
    }
}

final class FakeInstructions implements Instructions
{
    public const SYSTEM = 'Eres el asistente del panel de pruebas.';

    public const CONVERSATION_CONTEXT = '[Contexto] Escribe Ana, en el Colegio Demo.';

    public const TURN_CONTEXT = '[Momento] 2026-11-15 10:00, sucursal Central.';

    public function system(): string
    {
        return self::SYSTEM;
    }

    public function conversationContext(Authenticatable $user): string
    {
        return self::CONVERSATION_CONTEXT;
    }

    public function turnContext(PanelSnapshot $context, Authenticatable $user, CarbonInterface $now): string
    {
        return self::TURN_CONTEXT;
    }
}

final class FakeAccessGate implements AccessGate
{
    public function isEnabledForTenant(): bool
    {
        return true;
    }

    public function monthlyBudgetUsd(): float
    {
        return 100.0;
    }

    public function denialFor(?Authenticatable $user): ?string
    {
        return null;
    }

    public function canManageSettings(?Authenticatable $user): bool
    {
        return true;
    }

    public function canManagePlatformSettings(?Authenticatable $user): bool
    {
        return true;
    }

    public function message(string $code): string
    {
        return $code;
    }
}

final class FakeBudgetAlerts implements BudgetAlerts
{
    public function warnPlatform(int $usageId, string $period, float $costUsd, float $capUsd): void
    {
    }

    public function warnWorkspace(int $usageId, string $period, float $costUsd, float $capUsd): void
    {
    }
}

final class FakeWritePreviews implements WritePreviews
{
    public function refusal(array $payload): ?string
    {
        return null;
    }

    public function discard(string $token): void
    {
    }

    public function ttlSeconds(): int
    {
        return 600;
    }

    public function lockSeconds(): int
    {
        return 60;
    }
}

final class FakeToolSource implements ToolSource
{
    public function toolClasses(): array
    {
        return [];
    }

    public function extraToolClasses(): array
    {
        return [];
    }

    public function instructions(): string
    {
        return '';
    }

    public function isWrite(Tool $tool): bool
    {
        return false;
    }

    public function actionClasses(Tool $tool): ?array
    {
        return null;
    }

    public function actionClassOf(Tool $tool, array $arguments): ?string
    {
        return null;
    }
}

final class FakeToolBridge implements ToolBridge
{
    /**
     * @var list<array{tool: string, arguments: array<string, mixed>}>
     */
    public array $calls = [];

    public function definitions(): array
    {
        return [['name' => 'list-sales', 'description' => 'Lista ventas', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]]];
    }

    public function call(string $tool, array $arguments, string $runId, bool $fromModel = true): ToolCallResult
    {
        $this->calls[] = ['tool' => $tool, 'arguments' => $arguments];

        return new ToolCallResult(false, '{"ok":true}', ['ok' => true]);
    }
}
