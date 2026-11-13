<?php

namespace SchoolAid\Assistant\Services\Undo;

use SchoolAid\Assistant\Support\AssistantNaming;
use SchoolAid\Assistant\Models\AssistantConfirmation;

/**
 * Sabe invertir una escritura del grupo 1 ya confirmada. Hay uno por tool, en `AssistantNaming::config('undo')`.
 */
interface UndoPlanner
{
    /**
     * La llamada que deja el registro como estaba, o null si la confirmación no trae lo necesario para invertirla.
     */
    public function inverse(AssistantConfirmation $done): ?UndoCall;

    /**
     * Si la vista previa de la inversa deshace de verdad lo que hizo la escritura, sin efectos que la escritura no tuvo.
     *
     * @param  array<string, mixed>  $inversePreview
     */
    public function accepts(AssistantConfirmation $done, array $inversePreview): bool;
}
