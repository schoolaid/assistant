<?php

namespace SchoolAid\Assistant\Jobs;

use SchoolAid\Assistant\Jobs\Concerns\RemembersWorkspace;
use SchoolAid\Assistant\Enums\StepOutcomeEnum;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Services\Agent\AssistantTurnRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Un paso del turno: una llamada a la Messages API más sus tools ({@see AssistantTurnRunner::step()}). Con `Continue`
 * despacha el paso siguiente; cualquier otro resultado cierra la cadena (`Skipped`: otro worker tiene el paso o el
 * turno ya terminó).
 *
 * Conexión y cola propias (`assistant`, supervisor `supervisor-assistant`): un turno no espera detrás de los correos de
 * `default` ni los frena. `retry_after` de la conexión (240 s) > `timeout` (200 s) > timeout HTTP de la llamada
 * (120 s). Sin reintentos de la cola: el paso se reclama en la base y, si el worker muere, lo retoma
 * `assistant:resume-stale-turns`.
 */
class RunAssistantStep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RemembersWorkspace, SerializesModels;

    public const CONNECTION = 'assistant';

    public const QUEUE = 'assistant';

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(public readonly int $turnId)
    {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
        $this->rememberWorkspace();
    }

    public function handle(AssistantTurnRunner $runner): void
    {
        $turn = AssistantTurn::query()->find($this->turnId);

        if ($turn === null) {
            return;
        }

        if ($runner->step($turn) === StepOutcomeEnum::Continue) {
            self::dispatch($this->turnId);
        }
    }
}
