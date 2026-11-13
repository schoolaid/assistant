<?php

namespace SchoolAid\Assistant\Services\Anthropic;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Una llamada a la Messages API que no trajo respuesta: `status` es el HTTP de la API, null si no la hubo (sin llave,
 * sin conexión: `connectionFailed`).
 */
class AnthropicRequestFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly bool $connectionFailed = false,
    ) {
        parent::__construct($message);
    }

    /**
     * Vale la pena repetirla: no hubo conexión, límite de tasa (429), sobrecarga (529) u otro error del servidor (5xx).
     */
    public function isTransient(): bool
    {
        return $this->connectionFailed || $this->status === 429 || ($this->status !== null && $this->status >= 500);
    }

    /**
     * Textos con los que la API dice que el pedido es demasiado largo (400 `invalid_request_error`). La doc oficial
     * (Context windows, «Context window overflow behavior») cita «prompt is too long»; los demás cubren variantes de
     * tokens y contexto. Se comparan en minúsculas contra el mensaje del error.
     *
     * @var list<string>
     */
    private const LENGTH_MESSAGE_PATTERNS = [
        'prompt is too long',
        'too many tokens',
        'context window',
        'context length',
        'context limit',
    ];

    /**
     * El historial ya no cabe: 413 (`request_too_large`) siempre, o un 400 cuyo mensaje habla del largo. Repetirlo daría
     * lo mismo, así que cierra la conversación. Cualquier otro 400 (un header beta no habilitado, un campo rechazado por
     * un cambio de modelo o de versión) es culpa nuestra, no de la conversación: no la cierra.
     */
    public function exceedsRequestLimits(): bool
    {
        if ($this->status === 413) {
            return true;
        }

        return $this->status === 400 && Str::contains(Str::lower($this->getMessage()), self::LENGTH_MESSAGE_PATTERNS);
    }
}
