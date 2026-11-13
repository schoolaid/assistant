<?php

namespace SchoolAid\Assistant\Http\Controllers\Admin;

use SchoolAid\Assistant\Http\Controllers\AssistantController;
use SchoolAid\Assistant\Http\Requests\StoreAssistantFeedbackRequest;
use SchoolAid\Assistant\Models\AssistantMessage;
use Illuminate\Http\Response;

class AssistantFeedbackController extends AssistantController
{
    /**
     * Califica una respuesta; calificar otra vez la reemplaza. SAID lee los «no útil» con `assistant:feedback`.
     */
    public function store(StoreAssistantFeedbackRequest $request, AssistantMessage $message): Response
    {
        $message->forceFill([
            'helpful' => $request->boolean('helpful'),
            'feedback_comment' => $request->validated('comment'),
        ])->save();

        return response()->noContent();
    }
}
