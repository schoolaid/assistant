<?php

namespace SchoolAid\Assistant\Http\Controllers\Admin;

use SchoolAid\Assistant\Http\Controllers\AssistantController;
use SchoolAid\Assistant\Http\Resources\AssistantSuggestionResource;
use SchoolAid\Assistant\Services\AssistantSuggestions;
use SchoolAid\Assistant\Services\Settings\AssistantSettings;
use SchoolAid\Assistant\Services\Usage\AssistantBudget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssistantSuggestionController extends AssistantController
{
    /**
     * Las frases de arranque que la persona puede cumplir con sus permisos y su sucursal. Junto a `data`,
     * `budget_exceeded` ({@see AssistantBudget::isExceeded()}): el market llegó a su tope del mes y enviar o crear una
     * conversación respondería 429, así que la página lo avisa al cargar. `user_budget_exceeded`: lo mismo con el tope
     * personal de la persona. `api_key_invalid`: Anthropic rechazó la llave propia del market y enviar respondería 409.
     */
    public function index(Request $request, AssistantSuggestions $suggestions, AssistantBudget $budget, AssistantSettings $settings): AnonymousResourceCollection
    {
        return AssistantSuggestionResource::collection($suggestions->for($request->user()))
            ->additional([
                'budget_exceeded' => $budget->isExceeded(),
                'user_budget_exceeded' => $budget->isUserExceeded((int) $request->user()->getKey()),
                'api_key_invalid' => $settings->ownKeyRejected(),
            ]);
    }
}
