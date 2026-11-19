<?php

namespace SchoolAid\Assistant\Http\Resources;

use SchoolAid\Assistant\Contracts\EntityLabels;
use SchoolAid\Assistant\Models\AssistantExport;
use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Models\AssistantConfirmation;
use SchoolAid\Assistant\Models\AssistantMessage;
use SchoolAid\Assistant\Services\Entities\AssistantEntityExtractor;
use SchoolAid\Assistant\Services\Entities\AssistantMentions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un mensaje como lo muestra el panel: `text` es `display_text`, nunca `content` (el contexto del pedido, los bloques
 * de tools y lo crudo de un rechazo quedan fuera). `confirmation` solo en los mensajes `confirmation` y `choices` (la
 * pregunta y sus botones) solo en los `choices`; null en los demás. El panel ofrece los botones solo si el último
 * mensaje de la conversación es `choices`.
 *
 * `entities`: los registros que la persona adjuntó al mensaje ({@see AssistantMentions}, la forma de
 * {@see AssistantEntityResource}), etiquetados al leer: uno que ya no se puede ver deja de salir. `[]` si no tiene.
 *
 * Una colección de mensajes etiqueta de una vez los adjuntos de todos sus mensajes y los `entities` de todas sus
 * tarjetas ({@see self::newCollection()}): una consulta por tipo de registro, no por mensaje ni por tarjeta.
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
     * @var list<array{type: string, resource: string, id: int, date: string|null, label: string, detail: string|null, created: bool}>|null
     */
    private ?array $mentionEntities = null;

    /**
     * Los adjuntos de todos los mensajes y los `entities` de las tarjetas con su confirmación ya cargada
     * (`with('confirmation')`) se etiquetan en una sola llamada a {@see EntityLabels::labelEach()}; una tarjeta cuya
     * confirmación no vino cargada los resuelve al serializarse.
     *
     * @param  mixed  $resource
     */
    protected static function newCollection($resource): AnonymousResourceCollection
    {
        $collection = parent::newCollection($resource);
        $extractor = app(AssistantEntityExtractor::class);
        $lists = [];

        foreach ($collection->collection as $index => $message) {
            $lists["mentions.{$index}"] = AssistantMentions::fromPayload($message->resource->payload);

            if ($message->kind === AssistantMessageKindEnum::Confirmation
                && $message->resource->relationLoaded('confirmation')
                && $message->resource->confirmation instanceof AssistantConfirmation) {
                $lists["card.{$index}"] = $extractor->fromConfirmation($message->resource->confirmation);
            }
        }

        foreach (app(EntityLabels::class)->labelEach($lists) as $key => $entities) {
            [$list, $index] = explode('.', (string) $key, 2);
            $message = $collection->collection[(int) $index];

            if ($list === 'mentions') {
                $message->mentionEntities = $entities;
            } else {
                $message->confirmationEntities = $entities;
            }
        }

        return $collection;
    }

    /**
     * @return array{id: int, role: string, kind: string, text: string|null, entities: AnonymousResourceCollection, confirmation: AssistantConfirmationResource|null, choices: array{question: string, options: list<array{id: string, label: string, detail: string|null, selectable: bool}>}|null, chart: array<string, mixed>|null, file: AssistantExportResource|null, helpful: bool|null, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        $confirmation = $this->kind === AssistantMessageKindEnum::Confirmation ? $this->resource->confirmation : null;

        return [
            'id' => $this->id,
            'role' => $this->role,
            'kind' => $this->kind->value,
            'text' => $this->display_text,
            'entities' => AssistantEntityResource::collection($this->mentionEntities ?? $this->labelMentions()),
            'confirmation' => $confirmation === null ? null : $this->confirmationResource($confirmation),
            'choices' => $this->kind === AssistantMessageKindEnum::Choices ? $this->choicesForPanel() : null,
            'chart' => $this->kind === AssistantMessageKindEnum::Chart ? ((array) $this->resource->payload)['chart'] ?? null : null,
            'file' => $this->kind === AssistantMessageKindEnum::File ? $this->fileResource() : null,
            'helpful' => $this->helpful,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Los adjuntos de un mensaje serializado fuera de una colección.
     *
     * @return list<array{type: string, resource: string, id: int, date: string|null, label: string, detail: string|null, created: bool}>
     */
    private function labelMentions(): array
    {
        $refs = AssistantMentions::fromPayload($this->resource->payload);

        return $refs === [] ? [] : app(EntityLabels::class)->label($refs);
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
