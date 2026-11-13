<?php

namespace SchoolAid\Assistant\Services\Anthropic;

use SchoolAid\Assistant\Enums\AssistantKeySourceEnum;
use SchoolAid\Assistant\Services\Settings\AssistantApiKeys;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * POST /v1/messages con la llave de {@see AnthropicApiKeyResolver}.
 *
 * Un solo reintento ante una falla pasajera ({@see AnthropicRequestFailed::isTransient()}: sin conexión, 429, 529 u
 * otro 5xx), tras {@see self::RETRY_DELAY_SECONDS}, y solo si el primer intento falló rápido (antes de
 * {@see self::RETRY_WINDOW_SECONDS}): así el peor caso (30 s + 2 s + {@see self::TIMEOUT_SECONDS}) queda bajo el
 * `timeout` de 200 s del Job del paso con margen para sus tools. Un 4xx de la API no se repite.
 *
 * Un 401/403 con una llave guardada (la propia del market o la general) la marca `invalid`; con la propia lanza
 * {@see AnthropicApiKeyRejected}. La respuesta lleva de dónde salió la llave (`keySource`).
 */
class AnthropicMessagesClient
{
    public const RETRY_WINDOW_SECONDS = 30;

    private const RETRY_DELAY_SECONDS = 2;

    private const TIMEOUT_SECONDS = 120;

    public function __construct(private readonly AnthropicApiKeyResolver $keys)
    {
    }

    /**
     * @param array<string, mixed> $payload cuerpo de POST /v1/messages (model, max_tokens, system, tools, messages…)
     * @param list<string> $betas headers beta de esta llamada (`anthropic-beta`); vacío, no se envía
     *
     * @throws AnthropicRequestFailed
     */
    public function create(array $payload, array $betas = []): AnthropicResponse
    {
        $key = $this->keys->resolve();

        try {
            return $this->createWith($key, $payload, $betas)->withKeySource($key->source);
        } catch (AnthropicRequestFailed $failure) {
            throw $this->rejectedKey($key, $failure) ?? $failure;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $betas
     *
     * @throws AnthropicRequestFailed
     */
    private function createWith(ResolvedApiKey $key, array $payload, array $betas): AnthropicResponse
    {
        $headers = [
            'x-api-key' => $key->key,
            'anthropic-version' => (string) config('prism.providers.anthropic.version'),
        ];

        if ($betas !== []) {
            $headers['anthropic-beta'] = implode(',', $betas);
        }

        $retryUntil = now()->addSeconds(self::RETRY_WINDOW_SECONDS);

        try {
            return $this->send($headers, $payload);
        } catch (AnthropicRequestFailed $failure) {
            if (! $failure->isTransient() || now()->greaterThanOrEqualTo($retryUntil)) {
                throw $failure;
            }
        }

        Sleep::for(self::RETRY_DELAY_SECONDS)->seconds();

        return $this->send($headers, $payload);
    }

    /**
     * Anthropic no acepta la llave: si es una guardada, queda `invalid`; si es la propia del market, la falla pasa a
     * ser {@see AnthropicApiKeyRejected}.
     */
    private function rejectedKey(ResolvedApiKey $key, AnthropicRequestFailed $failure): ?AnthropicApiKeyRejected
    {
        if (! in_array($failure->status, [401, 403], true) || $key->row === null) {
            return null;
        }

        app(AssistantApiKeys::class)->markInvalid($key->row, $failure->getMessage());

        return $key->source === AssistantKeySourceEnum::Own
            ? new AnthropicApiKeyRejected($failure->getMessage(), $failure->status)
            : null;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     *
     * @throws AnthropicRequestFailed
     */
    private function send(array $headers, array $payload): AnthropicResponse
    {
        try {
            $response = Http::withHeaders($headers)
                ->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->post(rtrim((string) config('prism.providers.anthropic.url'), '/').'/messages', $payload);
        } catch (ConnectionException $exception) {
            throw new AnthropicRequestFailed('Anthropic request failed: '.$exception->getMessage(), connectionFailed: true);
        }

        if ($response->failed()) {
            throw new AnthropicRequestFailed(
                'Anthropic answered HTTP '.$response->status().': '.($response->json('error.message') ?? $response->body()),
                $response->status(),
            );
        }

        return AnthropicResponse::fromArray((array) $response->json());
    }
}
