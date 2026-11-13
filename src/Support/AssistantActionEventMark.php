<?php

namespace SchoolAid\Assistant\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Marca en el historial (spec del asistente, «Marca en el historial»; R-P2-9): cada action event que se crea mientras
 * corre una escritura confirmada desde el asistente lleva en `fields` `via` = `assistant`, el id de la confirmación y
 * el `run_id` del turno. El `user_id` del evento ya es quien confirmó: PanelContextScope lo autentica.
 *
 * `action_events` la escriben tres modelos (la app dice cuáles al registrar el listener, en su provider): el de Nadota (`ActionEventService`), el de Nova (los
 * listeners de app/Listeners/ModelAudit, que auditan cada `save()` con usuario) y el de la app. AssistantServiceProvider
 * registra un listener de `creating` fijo sobre los tres que no hace nada fuera de {@see self::during()}. Fijo y con
 * estado, no temporal: el dispatcher de Laravel no quita un listener suelto, y olvidar el evento entero borraría
 * también el `creating` propio del modelo de Nadota (llena `batch_id`).
 *
 * `ActionEventService` puede mandar el evento a la cola (`nadota.action_events.queue`): el Job lo crearía después, en
 * otro proceso y sin la marca. Mientras dura la escritura confirmada se escribe en línea, una fila más en la misma
 * request.
 *
 * `fields` con el cast de Nadota es un arreglo; en los otros dos, texto JSON (la auditoría guarda `{data, actor?}`). Un
 * texto que no es JSON (las acciones de Nova lo serializan) se deja como está: esas filas no salen de una tool.
 */
final class AssistantActionEventMark
{
    public const VIA = 'assistant';

    /**
     * @var array{via: string, assistant_confirmation_id: int, assistant_run_id: string}|null
     */
    private ?array $fields = null;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function during(int $confirmationId, string $runId, Closure $callback): mixed
    {
        $previousFields = $this->fields;
        $previousQueue = config('nadota.action_events.queue');

        $this->fields = ['via' => self::VIA, 'assistant_confirmation_id' => $confirmationId, 'assistant_run_id' => $runId];
        config(['nadota.action_events.queue' => false]);

        try {
            return $callback();
        } finally {
            $this->fields = $previousFields;
            config(['nadota.action_events.queue' => $previousQueue]);
        }
    }

    /**
     * El listener de `creating`: agrega la marca a `fields` si hay una escritura confirmada en curso.
     */
    public function apply(Model $actionEvent): void
    {
        if ($this->fields === null) {
            return;
        }

        $current = $actionEvent->getAttribute('fields');

        if ($actionEvent->hasCast('fields')) {
            $actionEvent->setAttribute('fields', [...(is_array($current) ? $current : []), ...$this->fields]);

            return;
        }

        $decoded = is_string($current) && trim($current) !== '' ? json_decode($current, true) : [];

        if (! is_array($decoded)) {
            return;
        }

        $actionEvent->setAttribute('fields', json_encode([...$decoded, ...$this->fields], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
