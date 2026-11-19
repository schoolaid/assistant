<?php

namespace SchoolAid\Assistant\Http\Controllers\Admin;

use SchoolAid\Assistant\Http\Controllers\AssistantController;
use SchoolAid\Assistant\Http\Requests\StoreAssistantMessageRequest;
use SchoolAid\Assistant\Http\Resources\AssistantConversationResource;
use SchoolAid\Assistant\Http\Resources\AssistantMessageResource;
use SchoolAid\Assistant\Http\Resources\AssistantTurnResource;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Services\AssistantMessageSender;
use SchoolAid\Assistant\Services\Entities\AssistantMentions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class AssistantMessageController extends AssistantController
{
    private const PER_PAGE = 30;

    public function __construct(private readonly AssistantMessageSender $sender)
    {
    }

    /**
     * Los mensajes que el panel muestra, del más reciente al más viejo, con la tarjeta de cada uno y su deshacer.
     * Junto a `data`, `active_turn` ({@see AssistantConversationResource::activeTurnOf()}): el turno al que hacerle poll;
     * y `conversation` (la forma de {@see AssistantConversationResource}): el título, el `outcome` y si está cerrada, que
     * la página que reabre la conversación no tiene si no está en la primera página del historial.
     */
    public function index(Request $request, AssistantConversation $conversation): AnonymousResourceCollection
    {
        $this->authorize('view', $conversation);

        $messages = $conversation->messages()
            ->visibleInPanel()
            ->with('confirmation.undoOffer')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return AssistantMessageResource::collection($messages)->additional([
            'active_turn' => AssistantConversationResource::activeTurnOf($conversation),
            'conversation' => (new AssistantConversationResource($conversation))->resolve($request),
        ]);
    }

    /**
     * 202 con el turno en cola; el panel hace poll de `assistant/turns/{turn}`. Con `choice_id` contesta la última
     * pregunta con opciones; con `content`, puede llevar `mentions` (registros adjuntos). Los 409, el 422 de una opción
     * que no se puede elegir y el de un adjunto que no se puede ver los responde
     * {@see \SchoolAid\Assistant\Services\AssistantRequestRefused}; el 429 del tope del mes,
     * {@see \SchoolAid\Assistant\Services\Usage\AssistantBudgetExceeded}.
     */
    public function store(StoreAssistantMessageRequest $request, AssistantConversation $conversation): JsonResponse
    {
        $choiceId = $request->validated('choice_id');

        $turn = $choiceId !== null
            ? $this->sender->sendChoice($conversation, $request->user(), (string) $choiceId)
            : $this->sender->send(
                $conversation,
                $request->user(),
                (string) $request->validated('content'),
                mentions: AssistantMentions::refs($request->validated('mentions') ?? []),
            );

        return (new AssistantTurnResource($turn))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
