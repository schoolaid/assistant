<?php

namespace SchoolAid\Assistant\Http\Resources;

use SchoolAid\Assistant\Contracts\PreviewDetails;
use SchoolAid\Assistant\Contracts\EntityLabels;
use SchoolAid\Assistant\Enums\AssistantConfirmationStatusEnum;
use SchoolAid\Assistant\Models\AssistantConfirmation;
use SchoolAid\Assistant\Services\Entities\AssistantEntityExtractor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La tarjeta de la vista previa: el `summary` y el detalle (`preview`) que calculó la tool, nunca texto del modelo, y
 * cómo terminó. Nunca el token ni los argumentos.
 *
 * `details`: el mismo `preview` como lo lee el staff ({@see PreviewDetails}): etiquetas en el idioma de la
 * request, montos con su moneda y sin las claves internas. Se calcula al leer; `preview` se queda tal cual.
 *
 * `executing`: true mientras una request la está ejecutando (`pending` con un reclamo de hace menos del candado del
 * token, {@see AssistantConfirmation::isExecuting()}): el panel muestra «ejecutando…» en vez de los botones, que
 * responderían 409. Un reclamo más viejo murió a medias y vuelve a `false`.
 *
 * `result`: con `confirmed`, el `result` que devolvió la tool; con `expired`, `{message}`; con `failed`, `{message}` o
 * `{reason: interrupted}` (la ejecución murió a medias: puede que el cambio se haya hecho; la cierra el mensaje
 * siguiente, ver AssistantMessageSender); con `rejected`, `{reason}` (`rejected` por el botón, `replaced_by_message`
 * si la persona escribió en vez de confirmar).
 *
 * `undo`: en una escritura del grupo 1 confirmada, `{id, summary, expires_at}` de su deshacer mientras siga `pending` y
 * sin vencer (se confirma con `confirmations/{id}/confirm`); null en los demás casos. `undoes_confirmation_id`: en un
 * deshacer, la confirmación que deshace.
 *
 * `undone`: true si su deshacer ya se confirmó (la relación `undoOffer` cargada, sin una consulta por tarjeta): la tarjeta
 * muestra «Deshecho» en vez de verse como una escritura vigente.
 *
 * `entities`: con `confirmed`, los registros que la escritura tocó o creó ({@see AssistantEntityResource}, la misma forma
 * que `conversations/{id}/entities`); `[]` en los demás estados. Una página de mensajes los etiqueta de una vez para
 * todas sus tarjetas ({@see self::entitiesOf()}, desde {@see AssistantMessageResource}); una tarjeta sola, al serializarse.
 *
 * @mixin AssistantConfirmation
 */
class AssistantConfirmationResource extends JsonResource
{
    /**
     * @var list<array{type: string, resource: string, id: int, date: string|null, label: string, detail: string|null, created: bool}>|null
     */
    private ?array $entities = null;

    /**
     * Los `entities` de varias tarjetas, por la misma llave, con una consulta por tipo de registro para todas.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, AssistantConfirmation>  $confirmations
     * @return array<TKey, list<array{type: string, resource: string, id: int, date: string|null, label: string, detail: string|null, created: bool}>>
     */
    public static function entitiesOf(array $confirmations): array
    {
        $extractor = app(AssistantEntityExtractor::class);

        return app(EntityLabels::class)->labelEach(
            array_map(fn (AssistantConfirmation $confirmation): array => $extractor->fromConfirmation($confirmation), $confirmations),
        );
    }

    /**
     * Los `entities` ya etiquetados con {@see self::entitiesOf()}.
     *
     * @param  list<array{type: string, resource: string, id: int, date: string|null, label: string, detail: string|null, created: bool}>  $entities
     */
    public function withEntities(array $entities): static
    {
        $this->entities = $entities;

        return $this;
    }

    /**
     * @return array{id: int, tool: string, summary: string, preview: array<string, mixed>, details: list<array<string, mixed>>, status: string, executing: bool, expires_at: string|null, result: array<string, mixed>|null, undoes_confirmation_id: int|null, undo: array{id: int, summary: string, expires_at: string|null}|null, undone: bool, entities: AnonymousResourceCollection}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tool' => $this->tool,
            'summary' => $this->summary,
            'preview' => (array) $this->preview,
            'details' => app(PreviewDetails::class)->present((array) $this->preview),
            'status' => $this->status->value,
            'executing' => $this->resource->isExecuting(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'result' => $this->result,
            'undoes_confirmation_id' => $this->undoes_confirmation_id,
            'undo' => $this->undo(),
            'undone' => $this->isUndone(),
            'entities' => $this->entities(),
        ];
    }

    private function isUndone(): bool
    {
        if ($this->status !== AssistantConfirmationStatusEnum::Confirmed || $this->undoes_confirmation_id !== null) {
            return false;
        }

        return $this->undoOffer?->status === AssistantConfirmationStatusEnum::Confirmed;
    }

    private function entities(): AnonymousResourceCollection
    {
        return AssistantEntityResource::collection($this->entities ?? self::entitiesOf([$this->resource])[0]);
    }

    /**
     * @return array{id: int, summary: string, expires_at: string|null}|null
     */
    private function undo(): ?array
    {
        $mayHaveUndo = $this->status === AssistantConfirmationStatusEnum::Confirmed
            && $this->undoes_confirmation_id === null
            && array_key_exists($this->tool, (array) config('assistant.undo'));

        if (! $mayHaveUndo) {
            return null;
        }

        $offer = $this->undoOffer;

        if ($offer === null || $offer->status !== AssistantConfirmationStatusEnum::Pending || $offer->expires_at->isPast()) {
            return null;
        }

        return ['id' => $offer->id, 'summary' => $offer->summary, 'expires_at' => $offer->expires_at->toIso8601String()];
    }
}
