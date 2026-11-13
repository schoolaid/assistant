<?php

namespace SchoolAid\Assistant\Enums;

/**
 * Cómo terminó un paso del turno ({@see \SchoolAid\Assistant\Services\Agent\AssistantTurnRunner::step()}).
 *
 * - `Continue`: el turno sigue `running`; la cola despacha el paso siguiente.
 * - `AwaitingConfirmation`, `Completed`, `Failed`: el turno quedó en ese estado y no hay más pasos.
 * - `Skipped`: no se reclamó el paso (otro worker lo tiene o el turno ya terminó); no se hizo nada.
 */
enum StepOutcomeEnum: string
{
    case Continue = 'continue';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
