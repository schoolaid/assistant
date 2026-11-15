<?php

namespace SchoolAid\Assistant\Services\Evaluation;

/**
 * Chequeo de las tareas en las que el asistente tiene que negarse (`mustRefuse`), sin llamar la API.
 *
 * **Anunció el registro:** `report-unanswered-question` es un registro interno de SAID y la persona no tiene que
 * enterarse. En producción (nadota, 2026-11-11) el asistente respondió «Ya quedó registrado» a un pedido que no podía
 * hacer: la persona lo leyó como hecho y lo pidió tres veces más. En una negativa no hay ninguna escritura, así que
 * nada «quedó registrado» de verdad; por eso el chequeo no corre en las demás tareas («el pago quedó registrado»).
 */
final class RefusalChecks
{
    public const ANNOUNCED_REPORT = 'le dijo a la persona que registró el pedido';

    private const ANNOUNCEMENTS = [
        '/\b(?:dej[eé]|qued[oó]|he)\s+(?:ya\s+)?registrad[oa]\b/iu',
        '/\bregistr[eé]\s+(?:tu|el|este|esta|su|la)\s+(?:pedido|solicitud|sugerencia|petici[oó]n|necesidad|caso)\b/iu',
    ];

    /**
     * @param  string  $visibleText  lo que la persona leyó del asistente en la conversación
     */
    public static function announcesReport(string $visibleText): bool
    {
        foreach (self::ANNOUNCEMENTS as $pattern) {
            if (preg_match($pattern, $visibleText) === 1) {
                return true;
            }
        }

        return false;
    }
}
