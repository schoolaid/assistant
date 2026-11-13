<?php

namespace SchoolAid\Assistant\Services;

use SchoolAid\Assistant\Support\AssistantNaming;
use Illuminate\Contracts\Auth\Authenticatable;
use SchoolAid\Assistant\Contracts\PanelScope;
use SchoolAid\Assistant\Services\Tools\AssistantToolBridge;

/**
 * Las frases de arranque del chat: de `AssistantNaming::config('suggestions')`, en orden, las que el usuario puede
 * cumplir con las tools que el puente le carga hoy (permisos, paquetes y sucursal ya aplicados por
 * {@see AssistantToolBridge::definitions()}), hasta `assistant.suggestions_limit`.
 */
class AssistantSuggestions
{
    public function __construct(
        private readonly PanelScope $scope,
        private readonly AssistantToolBridge $bridge,
    ) {
    }

    /**
     * @return list<array{id: string, text: string}>
     */
    public function for(Authenticatable $user): array
    {
        $available = array_column(
            $this->scope->run($this->scope->capture($user), fn (): array => $this->bridge->definitions()),
            'name',
        );
        $suggestions = [];

        foreach ((array) AssistantNaming::config('suggestions', []) as $suggestion) {
            if (array_diff($suggestion['tools'], $available) !== []) {
                continue;
            }

            $suggestions[] = ['id' => $suggestion['id'], 'text' => AssistantNaming::trans("suggestions.{$suggestion['id']}")];

            if (count($suggestions) >= (int) AssistantNaming::config('suggestions_limit', 4)) {
                break;
            }
        }

        return $suggestions;
    }
}
