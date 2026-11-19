<?php

namespace SchoolAid\Assistant\Http\Controllers\Admin;

use SchoolAid\Assistant\Contracts\EntityLabels;
use SchoolAid\Assistant\Http\Controllers\AssistantController;
use SchoolAid\Assistant\Http\Requests\LabelAssistantEntitiesRequest;
use SchoolAid\Assistant\Http\Requests\SearchAssistantEntitiesRequest;
use SchoolAid\Assistant\Http\Resources\AssistantEntityResource;
use SchoolAid\Assistant\Http\Resources\AssistantEntitySearchResultResource;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Services\Entities\AssistantEntityExtractor;
use SchoolAid\Assistant\Services\Entities\AssistantEntitySearcher;
use SchoolAid\Assistant\Services\Entities\AssistantMentions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssistantEntityController extends AssistantController
{
    public function __construct(
        private readonly AssistantEntityExtractor $extractor,
        private readonly EntityLabels $labeler,
        private readonly AssistantMentions $mentions,
    ) {
    }

    /**
     * Los registros que salieron en la conversación (los que el agente leyó y los que tocaron o crearon sus escrituras
     * confirmadas), para enlazarlos desde el panel. Sin paginar: son a lo más {@see AssistantEntityExtractor::MAX_ENTITIES}.
     */
    public function index(AssistantConversation $conversation): AnonymousResourceCollection
    {
        $this->authorize('view', $conversation);

        return AssistantEntityResource::collection($this->labeler->label($this->extractor->fromConversation($conversation)));
    }

    /**
     * Etiqueta registros antes de adjuntarlos a un mensaje: `data` va alineada con `refs`, con la entidad de cada uno o
     * `null` si no se puede ver (200, nunca 422 por eso). No pide una conversación: lo que protege es
     * {@see \SchoolAid\Assistant\Http\Middleware\EnsureAssistantAccess} y los scopes del etiquetador.
     */
    public function label(LabelAssistantEntitiesRequest $request): JsonResponse
    {
        $refs = AssistantMentions::refs($request->validated('refs'));

        return response()->json(['data' => $this->mentions->labelled($refs)]);
    }

    /**
     * Lo que ofrece el selector `@` del panel ({@see AssistantEntitySearcher::search()}): con `q`, lo que encontró la
     * app; sin texto y con `conversation`, sus registros más recientes. La conversación, si viene, tiene que ser de la
     * persona (403 si es de otra, 404 si no existe).
     *
     * El buscador llega como parámetro del método y no del constructor: así `index` y `label` resuelven aunque la app
     * no haya enlazado {@see \SchoolAid\Assistant\Contracts\EntitySearch}.
     */
    public function search(SearchAssistantEntitiesRequest $request, AssistantEntitySearcher $searcher): AnonymousResourceCollection
    {
        $conversation = null;

        if ($request->filled('conversation')) {
            $conversation = AssistantConversation::query()->findOrFail((int) $request->validated('conversation'));
            $this->authorize('view', $conversation);
        }

        return AssistantEntitySearchResultResource::collection(
            $searcher->search((string) $request->validated('q'), $conversation),
        );
    }
}
