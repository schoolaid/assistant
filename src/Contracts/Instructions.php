<?php

namespace SchoolAid\Assistant\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Lo que el agente lee antes de la conversación y lo que lleva cada pedido. Es contenido de la
 * app: sus reglas, sus palabras y los datos de su panel.
 */
interface Instructions
{
    /**
     * Instrucciones fijas, iguales para todos: el prefijo en caché de la API lo comparten todas las
     * conversaciones, así que aquí no va nada de la persona, del espacio de trabajo ni de la fecha.
     */
    public function system(): string;

    /**
     * Quién escribe y lo propio del espacio de trabajo: primer bloque del primer mensaje.
     */
    public function conversationContext(Authenticatable $user): string;

    /**
     * Fecha, hora y alcance de un pedido: un bloque de cada mensaje de la persona.
     */
    public function turnContext(PanelSnapshot $context, Authenticatable $user, CarbonInterface $now): string;
}
