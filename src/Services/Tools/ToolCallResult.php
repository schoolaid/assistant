<?php

namespace SchoolAid\Assistant\Services\Tools;

/**
 * Resultado de una llamada a una tool por el puente del asistente.
 *
 * `text` es lo que vuelve al modelo como `tool_result`: nunca trae el `confirmationToken` (va
 * tachado). El token sale solo en `confirmationToken`, para guardarlo en la confirmación que el
 * humano acepta con un clic. Es vista previa cuando la tool es de escritura en dos pasos y
 * respondió `status: preview` con su token. Una vista previa que toca una tarjeta no lo es: vuelve
 * como error con `blockedByCard`, sin token (el puente lo descartó), y se hace en el panel.
 */
final readonly class ToolCallResult
{
    /**
     * @param  array<string, mixed>|null  $payload  JSON de la respuesta ya decodificado (con el token tachado), o null si no era JSON
     */
    public function __construct(
        public bool $isError,
        public string $text,
        public ?array $payload = null,
        public bool $isPreview = false,
        public bool $blockedByCard = false,
        public ?string $confirmationToken = null,
        public ?string $summary = null,
        public ?string $expiresAt = null,
    ) {
    }

    public static function error(string $text): self
    {
        return new self(isError: true, text: $text);
    }
}
