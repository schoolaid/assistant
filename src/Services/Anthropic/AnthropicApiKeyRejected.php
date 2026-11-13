<?php

namespace SchoolAid\Assistant\Services\Anthropic;

/**
 * Anthropic rechazó (401/403) la llave propia del market, ahora o en una llamada anterior. El turno falla con
 * {@see \SchoolAid\Assistant\Services\Agent\AssistantTurnRunner::API_KEY_REJECTED_MESSAGE} y enviar otro mensaje
 * responde 409 `assistant_api_key_invalid` hasta que el market cambie o quite su llave.
 */
class AnthropicApiKeyRejected extends AnthropicRequestFailed
{
}
