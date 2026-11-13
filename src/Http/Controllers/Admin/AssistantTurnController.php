<?php

namespace SchoolAid\Assistant\Http\Controllers\Admin;

use SchoolAid\Assistant\Http\Controllers\AssistantController;
use SchoolAid\Assistant\Http\Resources\AssistantTurnResource;
use SchoolAid\Assistant\Models\AssistantTurn;

class AssistantTurnController extends AssistantController
{
    /**
     * El poll del panel hasta `completed`, `awaiting_confirmation` o `failed`.
     */
    public function show(AssistantTurn $turn): AssistantTurnResource
    {
        $this->authorize('view', $turn);

        return new AssistantTurnResource($turn);
    }
}
