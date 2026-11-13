<?php

namespace SchoolAid\Assistant\Support;

use InvalidArgumentException;

/**
 * El nombre con el que el asistente vive en la app: es la llave de su config y de sus
 * traducciones, el prefijo de sus tablas y el nombre de su limitador de peticiones. Por omisión
 * `assistant` (`config('assistant.*')`, `__('assistant.*')`, tablas `assistant_*`).
 *
 * Una app que ya tenga otro «assistant» registra el suyo con {@see self::use()} en el
 * `register()` de su provider, antes de que se lea la config o se use un modelo. El motor nunca
 * escribe `config('assistant.…')`, `__('assistant.…')` ni el nombre de una tabla: pregunta aquí.
 */
final class AssistantNaming
{
    public const DEFAULT = 'assistant';

    private static string $name = self::DEFAULT;

    /**
     * @throws InvalidArgumentException si el nombre no sirve como llave de config y prefijo de tabla
     */
    public static function use(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("El nombre del asistente [{$name}] debe ir en minúsculas, con letras, números y guiones bajos.");
        }

        self::$name = $name;
    }

    public static function name(): string
    {
        return self::$name;
    }

    /**
     * Un valor de la config del asistente (`model`, `exports.disk`…), o toda si no se pide llave.
     */
    public static function config(?string $key = null, mixed $default = null): mixed
    {
        return config($key === null ? self::$name : self::$name.'.'.$key, $default);
    }

    /**
     * El nombre de una tabla del asistente: `conversations` es `assistant_conversations`.
     */
    public static function table(string $suffix): string
    {
        return self::$name.'_'.$suffix;
    }

    /**
     * La llave completa de una traducción del asistente: `errors.x` es `assistant.errors.x`.
     */
    public static function langKey(string $key): string
    {
        return self::$name.'.'.$key;
    }

    /**
     * Una traducción del asistente; su llave completa si no existe o no es un texto.
     *
     * @param  array<string, mixed>  $replace
     */
    public static function trans(string $key, array $replace = []): string
    {
        $line = __(self::langKey($key), $replace);

        return is_string($line) ? $line : self::langKey($key);
    }
}
