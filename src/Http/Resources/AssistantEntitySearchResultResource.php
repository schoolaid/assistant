<?php

namespace SchoolAid\Assistant\Http\Resources;

use SchoolAid\Assistant\Services\Entities\AssistantEntityRef;
use SchoolAid\Assistant\Services\Entities\AssistantMentions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un resultado del selector `@` ({@see \SchoolAid\Assistant\Services\Entities\AssistantEntitySearcher}): `ref` es lo
 * que el panel manda tal cual en `mentions` ({@see AssistantMentions::toPayload()}), `group` el grupo en que lo
 * muestra (null en los registros de la conversación) y `entity` cómo se ve ({@see AssistantEntityResource}).
 *
 * @property array{ref: AssistantEntityRef, group: string|null, entity: array{type: string, resource: string, id: int, date: string|null, label: string, detail: string|null, created: bool}} $resource
 */
class AssistantEntitySearchResultResource extends JsonResource
{
    /**
     * @return array{ref: array{type: string, id: int, model_key: string|null}, group: string|null, entity: AssistantEntityResource}
     */
    public function toArray(Request $request): array
    {
        return [
            'ref' => AssistantMentions::toPayload([$this->resource['ref']])[0],
            'group' => $this->resource['group'],
            'entity' => new AssistantEntityResource($this->resource['entity']),
        ];
    }
}
