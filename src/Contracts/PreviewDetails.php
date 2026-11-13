<?php

namespace SchoolAid\Assistant\Contracts;

/**
 * La vista previa de una escritura como la lee el staff en la tarjeta de Confirmar: etiquetas de
 * la app, montos con moneda, sin claves internas.
 */
interface PreviewDetails
{
    /**
     * @param  array<array-key, mixed>  $preview  el `preview` que calculó la tool
     * @return list<array<string, mixed>>
     */
    public function present(array $preview): array;
}
