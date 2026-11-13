<?php

namespace SchoolAid\Assistant\Contracts;

/**
 * El membrete de los PDF del asistente: cómo se llama el espacio de trabajo y su logo. El motor arma el documento; la
 * app dice qué nombre y qué logo lleva.
 */
interface Letterhead
{
    /**
     * Nombre visible en el encabezado, o null si no tiene.
     */
    public function name(): ?string;

    /**
     * Logo como data URI PNG (`data:image/png;base64,…`), o null si no tiene. Va incrustado: el PDF se arma sin acceso
     * remoto.
     */
    public function logoDataUri(): ?string;
}
