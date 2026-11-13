<?php

namespace SchoolAid\Assistant\Http\Resources;

use SchoolAid\Assistant\Models\AssistantExport;
use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Models\AssistantConfirmation;
use SchoolAid\Assistant\Models\AssistantMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un mensaje como lo muestra el panel: `text` es `display_text`, nunca `content` (el contexto del pedido, los bloques
 * de tools y lo crudo de un rechazo quedan fuera). `confirmation` solo en los mensajes `confirmation` y `choices` (la
 * pregunta y sus botones) solo en los `choices`; null en los demás. El panel ofrece los botones solo si el último
 * mensaje de la conversación es `choices`.
 *
 * Una colección de mensajes etiqueta de una vez los `entities` de todas sus tarjetas ({@see self::newCollection()}): una
 * consulta por tipo de registro, no por tarjeta.
 *
 * @mixin AssistantMessage
 */
class AssistantMessageResource extends JsonResource
{
    /**
     * @var list<array{type: string, resource: string, id: int, date: string|null, label: string, detail: string|null, created: bool}>|null
     */
    private ?array $confirmationEntities = null;

    /**
     * Las tarjetas con su confirmación ya cargada (`with('confirmation')`) reciben sus `entities` en un solo lote; si no
     * vino cargada, la tarjeta los resuelve al serializarse.
     *
     * @param  mixed  $resource
     */
    protected static function newCollection($resource): AnonymousResourceCollection
    {
        $collection = parent::newCollection($resource);
        $cards = $collection->collection
            ->filter(fn (self $message): bool => $message->kind === AssistantMessageKindEnum::Confirmation
                && $message->resource->relationLoaded('confirmation')
                && $message->resource->confirmation instanceof AssistantConfirmation)
            ->map(fn (self $message): AssistantConfirmation => $message->resource->confirmation);

        foreach (AssistantConfirmationResource::entitiesOf($cards->all()) as $index => $entities) {
            $collection->collection[$index]->confirmationEntities = $entities;
        }

        return $collection;
    }

    /**
     * @return array{id: int, role: string, kind: string, text: string|null, confirmation: AssistantConfirmationResource|null, choices: array{question: string, options: list<array{id: string, label: string, detail: string|null, selectable: bool}>}|null, chart: array<string, mixed>|null, file: AssistantExportResource|null, helpful: bool|null, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        $confirmation = $this->kind === AssistantMessageKindEnum::Confirmation ? $this->resource->confirmation : null;

        return [
            'id' => $this->id,
            'role' => $this->role,
            'kind' => $this->kind->value,
            'text' => $this->display_text,
            'confirmation' => $confirmation === null ? null : $this->confirmationResource($confirmation),
            'choices' => $this->kind === AssistantMessageKindEnum::Choices ? $this->choicesForPanel() : null,
            'chart' => $this->kind === AssistantMessageKindEnum::Chart ? ((array) $this->resource->payload)['chart'] ?? null : null,
            'file' => $this->kind === AssistantMessageKindEnum::File ? $this->fileResource() : null,
            'helpful' => $this->helpful,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * El archivo del mensaje `file`, o null si ya no existe (se borró con su conversación).
     */
    private function fileResource(): ?AssistantExportResource
    {
        $export = AssistantExport::query()->find(((array) $this->resource->payload)['export_id'] ?? null);

        return $export === null ? null : new AssistantExportResource($export);
    }

    private function confirmationResource(AssistantConfirmation $confirmation): AssistantConfirmationResource
    {
        $card = new AssistantConfirmationResource($confirmation);

        return $this->confirmationEntities === null ? $card : $card->withEntities($this->confirmationEntities);
    }

    /**
     * @return array{question: string, options: list<array{id: string, label: string, detail: string|null, selectable: bool}>}
     */
    private function choicesForPanel(): array
    {
        $stored = (array) $this->resource->choices;
        $options = array_values(array_filter((array) ($stored['options'] ?? []), 'is_array'));

        return [
            'question' => (string) ($stored['question'] ?? ''),
            'options' => array_map(fn (array $option): array => [
                'id' => (string) ($option['id'] ?? ''),
                'label' => (string) ($option['label'] ?? ''),
                'detail' => isset($option['detail']) ? (string) $option['detail'] : null,
                'selectable' => (bool) ($option['selectable'] ?? true),
            ], $options),
        ];
    }
}
