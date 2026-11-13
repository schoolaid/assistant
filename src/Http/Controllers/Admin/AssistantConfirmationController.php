<?php

namespace SchoolAid\Assistant\Http\Controllers\Admin;

use SchoolAid\Assistant\Contracts\AccessGate;
use SchoolAid\Assistant\Http\Controllers\AssistantController;
use SchoolAid\Assistant\Http\Resources\AssistantTurnResource;
use SchoolAid\Assistant\Models\AssistantConfirmation;
use SchoolAid\Assistant\Services\ConfirmationExecutor;
use SchoolAid\Assistant\Services\Tools\AssistantUserInactive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Los botones de la tarjeta de la vista previa. Los 409 (`confirmation_not_pending`, un turno en curso o una
 * conversación cerrada) y el 403 de quien no pidió la acción los responde
 * {@see \SchoolAid\Assistant\Services\AssistantRequestRefused}.
 */
class AssistantConfirmationController extends AssistantController
{
    public function __construct(
        private readonly ConfirmationExecutor $executor,
        private readonly AccessGate $access,
    ) {
    }

    public function confirm(Request $request, AssistantConfirmation $confirmation): JsonResponse
    {
        $this->authorize('update', $confirmation);

        try {
            $turn = $this->executor->confirm($confirmation, $request->user());
        } catch (AssistantUserInactive) {
            return response()->json([
                'message' => $this->access->message(AccessGate::USER_INACTIVE),
                'code' => AccessGate::USER_INACTIVE,
            ], Response::HTTP_FORBIDDEN);
        }

        return $this->followUp(new AssistantTurnResource($turn));
    }

    public function reject(Request $request, AssistantConfirmation $confirmation): JsonResponse
    {
        $this->authorize('update', $confirmation);

        return $this->followUp(new AssistantTurnResource($this->executor->reject($confirmation, $request->user())));
    }

    private function followUp(AssistantTurnResource $turn): JsonResponse
    {
        return $turn->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
