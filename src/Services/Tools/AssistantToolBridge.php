<?php

namespace SchoolAid\Assistant\Services\Tools;

use SchoolAid\Assistant\Support\AssistantNaming;
use SchoolAid\Assistant\Contracts\PanelScope;
use SchoolAid\Assistant\Contracts\ToolCallLog;
use SchoolAid\Assistant\Contracts\ToolSource;
use SchoolAid\Assistant\Contracts\WritePreviews;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Support\ValidationMessages;
use Throwable;
use UnexpectedValueException;

/**
 * Ejecuta en proceso las mismas clases de tools del servidor MCP de la app ({@see ToolSource}): mismo `shouldRegister()`
 * (revisado otra vez en cada llamada), mismo `handle()` y, por eso, mismos permisos, policies y
 * scopes de sucursal que una llamada por `/mcp/admin`; la sesión, el usuario y el principal los
 * pone {@see PanelContextScope}.
 *
 * Lo que agrega el puente:
 * - Solo carga y llama lo que {@see AssistantToolCatalog} permite: lecturas y escrituras con
 *   grupo, sin las negadas, más las lecturas propias del asistente (`read-catalog`). Una
 *   escritura (o acción de `manage-subscription`) sin grupo responde «Eso se hace en el panel.»
 *   sin llamar la tool.
 * - Una escritura nunca se confirma por el modelo: de sus llamadas se quitan `confirmationToken` e
 *   `idempotencyKey`, el schema no los muestra y el token se tacha de lo que vuelve al modelo.
 * - Una vista previa que {@see WritePreviews::refusal()} no deja ofrecer (en MarketAid, la que toca
 *   una tarjeta) no llega a Confirmar: el token se descarta y vuelve como error `blockedByCard`.
 * - Los errores de validación y las excepciones vuelven como resultado de error, como hace
 *   `CallTool` de laravel/mcp, para que un argumento malo no tumbe el turno. De una excepción el
 *   modelo solo ve la clase; el mensaje va a `report()` y a la fila del log.
 * - Cada llamada empieza con la sucursal fijada: las tools con `branchOfficeId`
 *   (AppliesBranchSelection) estrechan la selección de la sesión, y la sesión del contexto dura
 *   todo el turno; sin restaurarla, las llamadas siguientes correrían en esa sucursal.
 * - Cada llamada deja su fila en la bitácora de tools de la app ({@see ToolCallLog}), con el
 *   `run_id` del turno.
 */
class AssistantToolBridge implements ToolBridge
{
    public const HIDDEN = ToolCallLog::HIDDEN;

    private const NOT_AVAILABLE = 'Esa tool no existe o no tienes permiso.';

    private const PANEL_ONLY = 'Eso se hace en el panel.';

    private const PREVIEW_NEXT = 'Muestra la vista previa a la persona en una frase y espera: ella confirma o rechaza con el botón del panel. No repitas esta llamada.';

    /**
     * Argumentos con los que se confirma una escritura: el modelo no los ve ni los manda.
     */
    private const CONFIRMATION_ARGUMENTS = ['confirmationToken', 'idempotencyKey'];

    public function __construct(
        private readonly AssistantToolCatalog $catalog,
        private readonly ToolSource $source,
        private readonly WritePreviews $previews,
        private readonly ToolCallLog $toolLog,
        private readonly PanelScope $scope,
    ) {
    }

    public function definitions(): array
    {
        $deferOutsideCore = (bool) AssistantNaming::config('tool_search.enabled', true);
        $definitions = [];

        foreach ([...$this->catalog->tools(), ...$this->catalog->extraTools()] as $tool) {
            if (! $this->isEligible($tool, new Request([]))) {
                continue;
            }

            $definition = $tool->toArray();
            $entry = [
                'name' => $definition['name'],
                'description' => (string) ($definition['description'] ?? ''),
                'input_schema' => $this->inputSchema($definition['inputSchema'] ?? []),
            ];

            if ($deferOutsideCore && ! $this->catalog->isCore($entry['name'])) {
                $entry['defer_loading'] = true;
            }

            $definitions[] = $entry;
        }

        usort($definitions, fn (array $first, array $second): int => strcmp($first['name'], $second['name']));

        return $definitions;
    }

    public function call(string $tool, array $arguments, string $runId, bool $fromModel = true): ToolCallResult
    {
        $startedAt = microtime(true);

        if ($fromModel) {
            $arguments = Arr::except($arguments, self::CONFIRMATION_ARGUMENTS);
        }

        $sentToken = is_string($arguments['confirmationToken'] ?? null) ? $arguments['confirmationToken'] : null;
        $instance = $this->catalog->find($tool);

        [$result, $exceptionDetail] = match (true) {
            $instance === null => [ToolCallResult::error(self::NOT_AVAILABLE), null],
            $this->catalog->groupOf($instance, $arguments) === null => [ToolCallResult::error(self::PANEL_ONLY), null],
            default => $this->invoke($instance, $arguments, $sentToken),
        };

        $this->log($tool, $arguments, $runId, $result, $startedAt, $exceptionDetail);

        return $result;
    }

    /**
     * `handle()` con el mismo request que vería por el servidor MCP: `mcp.request` ligado mientras
     * dura, porque laravel/mcp llena con él cualquier `Request` que el contenedor resuelva. La
     * llamada va dentro de {@see PanelScope::isolateCall()}: al salir, el alcance de la sesión
     * vuelve al de antes de la llamada.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: ToolCallResult, 1: string|null}  el resultado y, si hubo una excepción inesperada, «Clase: mensaje» para el log
     */
    private function invoke(Tool $tool, array $arguments, ?string $sentToken): array
    {
        return $this->scope->isolateCall(fn (): array => $this->handle($tool, $arguments, $sentToken));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: ToolCallResult, 1: string|null}
     */
    private function handle(Tool $tool, array $arguments, ?string $sentToken): array
    {
        $request = new Request($arguments);
        $previousRequest = app()->bound('mcp.request') ? app('mcp.request') : null;
        app()->instance('mcp.request', $request);

        try {
            if (! $this->isEligible($tool, $request)) {
                return [ToolCallResult::error(self::NOT_AVAILABLE), null];
            }

            $response = app()->call([$tool, 'handle'], ['request' => $request]);

            return [$this->resultFrom($tool, $response, $sentToken), null];
        } catch (ValidationException $exception) {
            return [ToolCallResult::error(ValidationMessages::from($exception)), null];
        } catch (Throwable $exception) {
            report($exception);

            return [
                ToolCallResult::error('La tool falló (' . class_basename($exception) . ').'),
                $exception::class . ': ' . $exception->getMessage(),
            ];
        } finally {
            app()->forgetInstance('mcp.request');

            if ($previousRequest !== null) {
                app()->instance('mcp.request', $previousRequest);
            }
        }
    }

    private function isEligible(Tool $tool, Request $request): bool
    {
        return ! method_exists($tool, 'shouldRegister')
            || (bool) app()->call([$tool, 'shouldRegister'], ['request' => $request]);
    }

    /**
     * El schema de `tools/list` sin los argumentos de confirmación, con `properties` como objeto
     * JSON aunque quede vacío (la Messages API rechaza `[]`).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function inputSchema(array $schema): array
    {
        $properties = Arr::except((array) ($schema['properties'] ?? []), self::CONFIRMATION_ARGUMENTS);
        $schema['properties'] = (object) $properties;

        if (isset($schema['required']) && is_array($schema['required'])) {
            $required = array_values(array_diff($schema['required'], self::CONFIRMATION_ARGUMENTS));

            if ($required === []) {
                unset($schema['required']);
            } else {
                $schema['required'] = $required;
            }
        }

        return $schema;
    }

    private function resultFrom(Tool $tool, mixed $response, ?string $sentToken): ToolCallResult
    {
        $responses = match (true) {
            $response instanceof Response => [$response],
            $response instanceof ResponseFactory => $response->responses()->all(),
            default => throw new UnexpectedValueException('La tool no devolvió una respuesta MCP.'),
        };

        $text = implode("\n", array_map(fn (Response $item): string => (string) $item->content(), $responses));
        $isError = collect($responses)->contains(fn (Response $item): bool => $item->isError());
        $decoded = json_decode($text, true);
        $payload = is_array($decoded) ? $decoded : null;

        $token = ! $isError && $this->source->isWrite($tool) && ($payload['status'] ?? null) === 'preview' && is_string($payload['confirmationToken'] ?? null)
            ? $payload['confirmationToken']
            : null;

        if ($token === null) {
            return new ToolCallResult($isError, $this->redact($text, $sentToken), $payload);
        }

        $payload['confirmationToken'] = self::HIDDEN;

        $refusal = $this->previews->refusal($payload);

        if ($refusal !== null) {
            return $this->refused($token, $payload, $refusal);
        }

        $payload['next'] = self::PREVIEW_NEXT;
        $visibleText = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new ToolCallResult(
            isError: false,
            text: $this->redact($this->redact($visibleText, $token), $sentToken),
            payload: $payload,
            isPreview: true,
            confirmationToken: $token,
            summary: is_string($payload['preview']['summary'] ?? null) ? $payload['preview']['summary'] : null,
            expiresAt: is_string($payload['expiresAt'] ?? null) ? $payload['expiresAt'] : null,
        );
    }

    /**
     * La vista previa no se ofrece: el token se descarta (nadie puede confirmarlo, ni con el botón)
     * y al modelo vuelve el motivo como error.
     *
     * @param  array<string, mixed>  $payload  con el token ya tachado
     */
    private function refused(string $token, array $payload, string $refusal): ToolCallResult
    {
        $this->previews->discard($token);

        unset($payload['next']);

        return new ToolCallResult(
            isError: true,
            text: $refusal,
            payload: $payload,
            blockedByCard: true,
        );
    }

    private function redact(string $text, ?string $token): string
    {
        return $token === null || $token === '' ? $text : str_replace($token, self::HIDDEN, $text);
    }

    /**
     * Best-effort: un fallo de la bitácora nunca cambia el resultado de la tool.
     *
     * @param  array<string, mixed>  $arguments
     * @param  string|null  $exceptionDetail  «Clase: mensaje» de una excepción inesperada
     */
    private function log(string $tool, array $arguments, string $runId, ToolCallResult $result, float $startedAt, ?string $exceptionDetail): void
    {
        try {
            $this->toolLog->record($tool, $arguments, $runId, $result, (int) round((microtime(true) - $startedAt) * 1000), $exceptionDetail);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
