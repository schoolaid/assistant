<?php

namespace SchoolAid\Assistant\Services\Anthropic;

use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Enums\AssistantApiKeyStatusEnum;
use SchoolAid\Assistant\Enums\AssistantKeySourceEnum;
use SchoolAid\Assistant\Services\Settings\AssistantSettings;

/**
 * Único punto donde se decide con qué llave se llama a Anthropic ({@see AssistantSettings::keySource()}):
 *
 * - La propia del market, si central se lo permite y la cargó. Si Anthropic ya la rechazó (`invalid`), falla con
 *   {@see AnthropicApiKeyRejected} sin llamar: no se cambia por la de SAID, que pagaría sin tope.
 * - Si no, la de SAID: la general de central (si no la rechazaron) o `ANTHROPIC_API_KEY`.
 *
 * Fuera de la tenancy (comandos de central) solo hay la de SAID.
 */
class AnthropicApiKeyResolver
{
    public function __construct(
        private readonly AssistantSettings $settings,
        private readonly Workspace $workspace,
    ) {
    }

    /**
     * @throws AnthropicRequestFailed
     */
    public function resolve(): ResolvedApiKey
    {
        if ($this->workspace->id() !== null && $this->settings->keySource() === AssistantKeySourceEnum::Own) {
            $own = $this->settings->ownKey();

            if ($own->status === AssistantApiKeyStatusEnum::Invalid) {
                throw new AnthropicApiKeyRejected('The market API key was rejected by Anthropic: '.$own->last_error);
            }

            return new ResolvedApiKey($own->api_key, AssistantKeySourceEnum::Own, $own);
        }

        $general = $this->settings->generalKey();

        if ($general !== null && $general->status === AssistantApiKeyStatusEnum::Valid) {
            return new ResolvedApiKey($general->api_key, AssistantKeySourceEnum::Said, $general);
        }

        $key = AnthropicSettings::apiKey();

        if ($key === '') {
            throw new AnthropicRequestFailed('ANTHROPIC_API_KEY is not configured.');
        }

        return new ResolvedApiKey($key, AssistantKeySourceEnum::Said, null);
    }
}
