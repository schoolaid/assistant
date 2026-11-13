<?php

namespace SchoolAid\Assistant\Services;

use SchoolAid\Assistant\Support\AssistantNaming;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un pedido al asistente que el panel rechaza con una respuesta normal, no un error: `{message, code}` con su status
 * (`docs/rules/14-responses-errors.md`) y sin reporte. El mensaje es `assistant.errors.<code>`.
 */
class AssistantRequestRefused extends RuntimeException implements ShouldntReport
{
    public const TURN_IN_PROGRESS = 'assistant_turn_in_progress';

    public const CONVERSATION_TOO_LONG = 'assistant_conversation_too_long';

    public const CONFIRMATION_FORBIDDEN = 'assistant_confirmation_forbidden';

    public const CHOICE_NOT_AVAILABLE = 'assistant_choice_not_available';

    public const API_KEY_INVALID = 'assistant_api_key_invalid';

    public function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }

    /**
     * La conversación tiene un paso en cola o en curso, o una confirmación que se está ejecutando.
     */
    public static function turnInProgress(): self
    {
        return new self(self::TURN_IN_PROGRESS, Response::HTTP_CONFLICT);
    }

    /**
     * La última respuesta llegó a `assistant.max_conversation_input_tokens`: el panel ofrece una conversación nueva.
     */
    public static function conversationTooLong(): self
    {
        return new self(self::CONVERSATION_TOO_LONG, Response::HTTP_CONFLICT);
    }

    /**
     * Quien confirma no es quien pidió la acción (el usuario guardado en el contexto del turno).
     */
    public static function confirmationForbidden(): self
    {
        return new self(self::CONFIRMATION_FORBIDDEN, Response::HTTP_FORBIDDEN);
    }

    /**
     * La opción elegida no está en la última pregunta con opciones sin contestar de la conversación, o no se puede
     * elegir.
     */
    public static function choiceNotAvailable(): self
    {
        return new self(self::CHOICE_NOT_AVAILABLE, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Anthropic rechazó la llave propia del market: no se abre un turno que fallaría, ni se usa la de SAID. Se destraba
     * cuando el market cambia o quita su llave en la configuración del asistente.
     */
    public static function apiKeyInvalid(): self
    {
        return new self(self::API_KEY_INVALID, Response::HTTP_CONFLICT);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => AssistantNaming::trans("errors.{$this->errorCode}"),
            'code' => $this->errorCode,
        ], $this->status);
    }
}
