<?php

namespace SchoolAid\Assistant\Services\Anthropic;

use SchoolAid\Assistant\Support\AssistantNaming;

/**
 * Dónde está la Messages API y con qué llave de la plataforma: `anthropic.api_key`, `anthropic.url`
 * y `anthropic.version` de la config del asistente.
 *
 * MarketAid todavía los tiene en la config de Prism (`prism.providers.anthropic.*`): mientras no
 * estén en la del asistente, se leen de ahí.
 */
final class AnthropicSettings
{
    public static function apiKey(): string
    {
        return (string) self::value('api_key');
    }

    /**
     * Sin la barra final.
     */
    public static function url(): string
    {
        return rtrim((string) self::value('url'), '/');
    }

    public static function version(): string
    {
        return (string) self::value('version');
    }

    private static function value(string $key): mixed
    {
        return AssistantNaming::config("anthropic.{$key}") ?? config("prism.providers.anthropic.{$key}");
    }
}
