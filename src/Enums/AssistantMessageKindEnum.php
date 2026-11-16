<?php

namespace SchoolAid\Assistant\Enums;

enum AssistantMessageKindEnum: string
{
    case Text = 'text';
    case Confirmation = 'confirmation';
    /**
     * La pregunta con opciones que el modelo pidió con `offer-choices`: el panel la muestra con botones.
     */
    case Choices = 'choices';
    /**
     * La gráfica que el modelo pidió con `show-chart`: el spec va en `payload` y el panel la dibuja.
     */
    case Chart = 'chart';
    /**
     * El archivo que el modelo pidió con `export-file`: `payload.export_id` apunta a su AssistantExport.
     */
    case File = 'file';
    case Error = 'error';
    case Internal = 'internal';
    /**
     * El resumen que reemplaza lo anterior de una conversación larga, o un intento que no lo logró
     * ({@see \SchoolAid\Assistant\Services\Agent\ConversationSummary}). Lo lee el modelo; el panel nunca lo muestra.
     */
    case Summary = 'summary';
}
