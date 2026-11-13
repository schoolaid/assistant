<?php

namespace SchoolAid\Assistant\Services\Usage;

use SchoolAid\Assistant\Contracts\AccessGate;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * El market llegó a su tope del mes ({@see AssistantBudget::isExceeded()}) o la persona al suyo
 * ({@see AssistantBudget::isUserExceeded()}). En una request del panel responde el 429 `assistant_budget_exceeded` o
 * `assistant_user_budget_exceeded` (`{message, code}`), igual al enviar un mensaje que al crear una conversación; no
 * es un error que reportar.
 */
class AssistantBudgetExceeded extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $accessCode = AccessGate::BUDGET_EXCEEDED)
    {
        parent::__construct($accessCode);
    }

    public static function forUser(): self
    {
        return new self(AccessGate::USER_BUDGET_EXCEEDED);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => app(AccessGate::class)->message($this->accessCode),
            'code' => $this->accessCode,
        ], Response::HTTP_TOO_MANY_REQUESTS);
    }
}
