<?php

namespace SchoolAid\Assistant\Services\Settings;

use SchoolAid\Assistant\Enums\AssistantApiKeyStatusEnum;
use SchoolAid\Assistant\Models\AssistantApiKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Guarda, quita y describe las llaves de Anthropic del asistente: la general (`$tenantId` null) y la propia de cada
 * market. Antes de guardar, la prueba con `GET /v1/models` (gratis, no consume tokens): si Anthropic no la acepta o no
 * se pudo probar, no se guarda. Ninguna respuesta lleva la llave: {@see self::state()} da `last4` y el estado.
 */
class AssistantApiKeys
{
    private const VERIFY_TIMEOUT_SECONDS = 15;

    private const MAX_ERROR_CHARS = 500;

    /**
     * @throws AssistantSettingsRejected 422 `assistant_api_key_invalid` en `errors.api_key`
     */
    public function save(?string $tenantId, string $apiKey, ?string $updatedBy): AssistantApiKey
    {
        $apiKey = trim($apiKey);
        $failure = $this->verify($apiKey);

        if ($failure !== null) {
            throw AssistantSettingsRejected::on('api_key', AssistantSettingsRejected::API_KEY_INVALID, ['reason' => $failure]);
        }

        $row = $this->find($tenantId) ?? new AssistantApiKey(['tenant_id' => $tenantId]);
        $row->fill([
            'api_key' => $apiKey,
            'last4' => Str::substr($apiKey, -4),
            'status' => AssistantApiKeyStatusEnum::Valid,
            'verified_at' => now(),
            'last_error' => null,
            'updated_by' => $updatedBy,
        ])->save();

        return $row;
    }

    public function delete(?string $tenantId): void
    {
        $this->find($tenantId)?->delete();
    }

    /**
     * Anthropic rechazó la llave en uso (401/403): queda `invalid` hasta que la cambien. No se reemplaza por otra.
     */
    public function markInvalid(AssistantApiKey $key, string $error): void
    {
        AssistantApiKey::query()->whereKey($key->getKey())->update([
            'status' => AssistantApiKeyStatusEnum::Invalid->value,
            'last_error' => Str::limit($error, self::MAX_ERROR_CHARS),
        ]);
    }

    /**
     * @return array{last4: string, status: string, verified_at: string|null, last_error: string|null}|null
     */
    public function state(?AssistantApiKey $key): ?array
    {
        if ($key === null) {
            return null;
        }

        return [
            'last4' => $key->last4,
            'status' => $key->status->value,
            'verified_at' => $key->verified_at?->toIso8601String(),
            'last_error' => $key->last_error,
        ];
    }

    private function find(?string $tenantId): ?AssistantApiKey
    {
        return AssistantApiKey::query()
            ->when($tenantId === null, fn ($query) => $query->whereNull('tenant_id'), fn ($query) => $query->where('tenant_id', $tenantId))
            ->first();
    }

    /**
     * Motivo por el que la llave no sirve, o null si Anthropic la aceptó.
     */
    private function verify(string $apiKey): ?string
    {
        if ($apiKey === '') {
            return __('assistant.settings.api_key_empty');
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => (string) config('prism.providers.anthropic.version'),
            ])
                ->timeout(self::VERIFY_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get(rtrim((string) config('prism.providers.anthropic.url'), '/').'/models', ['limit' => 1]);
        } catch (ConnectionException) {
            return __('assistant.settings.api_key_unreachable');
        }

        if ($response->successful()) {
            return null;
        }

        return in_array($response->status(), [401, 403], true)
            ? __('assistant.settings.api_key_rejected')
            : __('assistant.settings.api_key_unverified', ['status' => $response->status()]);
    }
}
