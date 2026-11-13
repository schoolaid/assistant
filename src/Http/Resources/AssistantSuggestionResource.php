<?php

namespace SchoolAid\Assistant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una frase de arranque del chat, tal como la devuelve AssistantSuggestions::for().
 *
 * @property array{id: string, text: string} $resource
 */
class AssistantSuggestionResource extends JsonResource
{
    /**
     * @return array{id: string, text: string}
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource['id'], 'text' => $this->resource['text']];
    }
}
