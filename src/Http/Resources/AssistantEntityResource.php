<?php

namespace SchoolAid\Assistant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un registro de la conversación tal como lo devuelve {@see \SchoolAid\Assistant\Services\Entities\AssistantEntityLabeler}:
 * el panel lo enlaza a la ficha de Nadota con `resource` + `id`. `date`: en un horario, la clase consultada.
 * `created`: lo creó una escritura confirmada.
 *
 * @property array{type: string, resource: string, id: int, date: string|null, label: string, detail: string|null, created: bool} $resource
 */
class AssistantEntityResource extends JsonResource
{
    /**
     * @return array{type: string, resource: string, id: int, date: string|null, label: string, detail: string|null, created: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource['type'],
            'resource' => $this->resource['resource'],
            'id' => $this->resource['id'],
            'date' => $this->resource['date'],
            'label' => $this->resource['label'],
            'detail' => $this->resource['detail'],
            'created' => $this->resource['created'],
        ];
    }
}
