<?php

namespace SchoolAid\Assistant\Http\Controllers\Admin;

use SchoolAid\Assistant\Contracts\EntityLabels;
use SchoolAid\Assistant\Http\Controllers\AssistantController;
use SchoolAid\Assistant\Http\Resources\AssistantEntityResource;
use SchoolAid\Assistant\Models\AssistantConversation;
use SchoolAid\Assistant\Services\Entities\AssistantEntityExtractor;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssistantEntityController extends AssistantController
{
    public function __construct(
        private readonly AssistantEntityExtractor $extractor,
        private readonly EntityLabels $labeler,
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
}
