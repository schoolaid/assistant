<?php

namespace SchoolAid\Assistant\Http\Controllers\Admin;

use SchoolAid\Assistant\Http\Controllers\AssistantController;
use SchoolAid\Assistant\Http\Requests\StoreAssistantConversationRequest;
use SchoolAid\Assistant\Http\Resources\AssistantConversationResource;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Services\Usage\AssistantBudget;
use SchoolAid\Assistant\Services\Usage\AssistantBudgetExceeded;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las conversaciones de quien está en el panel: solo las suyas (nadie más las ve, ni un admin).
 */
class AssistantConversationController extends AssistantController
{
    private const PER_PAGE = 20;

    public function __construct(private readonly AssistantBudget $budget)
    {
    }

    /**
     * Con `outcome` de cada una calculado en la misma consulta ({@see AssistantConversation::scopeWithKeptWrite()}) y
     * `closed` de toda la página en un lote ({@see AssistantConversationResource::newCollection()}).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $conversations = AssistantConversation::query()
            ->where('user_id', $request->user()->getKey())
            ->withKeptWrite()
            ->with('activeTurn')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return AssistantConversationResource::collection($conversations);
    }

    /**
     * Con el tope del mes alcanzado (del market o de la persona), el mismo 429 que al enviar, sin crear nada: si no, cada
     * primer mensaje bloqueado dejaría una conversación vacía.
     *
     * @throws AssistantBudgetExceeded
     */
    public function store(StoreAssistantConversationRequest $request): JsonResponse
    {
        $this->budget->ensureWithinBudget((int) $request->user()->getKey());

        $conversation = AssistantConversation::query()->create([
            'user_id' => $request->user()->getKey(),
            'title' => $request->validated('title'),
        ]);

        $conversation->setRelation('activeTurn', null);

        return (new AssistantConversationResource($conversation))
            ->withClosed(false)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
