<?php

namespace SchoolAid\Assistant\Services\Settings;

use SchoolAid\Assistant\Support\AssistantNaming;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un cambio de llave o de límites del asistente que no se guardó: `{message, code, errors}` con el campo que lo causó,
 * para que el panel lo pinte en el formulario. Nada quedó escrito.
 */
class AssistantSettingsRejected extends RuntimeException implements ShouldntReport
{
    public const LIMIT_ABOVE_MAX = 'assistant_limit_above_max';

    public const LIMIT_LOCKED = 'assistant_limit_locked';

    public const API_KEY_INVALID = 'assistant_api_key_invalid';

    public const OWN_KEY_NOT_ALLOWED = 'assistant_own_key_not_allowed';

    /**
     * @param  array<string, list<string>>  $errors  por campo
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly array $errors,
        public readonly int $httpStatus = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct((string) (collect($errors)->flatten()->first() ?? AssistantNaming::trans("settings.{$errorCode}")));
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    public static function on(string $field, string $code, array $replace = [], int $httpStatus = Response::HTTP_UNPROCESSABLE_ENTITY): self
    {
        return new self($code, [$field => [AssistantNaming::trans("settings.{$code}", $replace)]], $httpStatus);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'errors' => $this->errors,
        ], $this->httpStatus);
    }
}
