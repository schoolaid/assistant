<?php

namespace SchoolAid\Assistant\Contracts;

/**
 * Las vistas previas de las escrituras del servidor MCP de la app, vistas desde el asistente: si
 * una se puede ofrecer para Confirmar, cómo se descarta su token y cuánto viven.
 */
interface WritePreviews
{
    /**
     * Por qué esta vista previa no se ofrece para Confirmar, en una frase para el modelo, o null
     * si se ofrece.
     *
     * @param  array<string, mixed>  $payload  la respuesta de la tool, con el token ya tachado
     */
    public function refusal(array $payload): ?string;

    /**
     * Descarta el token de una vista previa que no se va a ofrecer: nadie puede confirmarlo.
     */
    public function discard(string $token): void;

    /**
     * Segundos que vive una vista previa cuando la tool no dice cuándo vence.
     */
    public function ttlSeconds(): int;

    /**
     * Segundos del candado de una confirmación en curso (el tope de una request): un reclamo más
     * viejo murió a medias.
     */
    public function lockSeconds(): int;
}
