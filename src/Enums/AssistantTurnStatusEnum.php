<?php

namespace SchoolAid\Assistant\Enums;

enum AssistantTurnStatusEnum: string
{
    case Queued = 'queued';
    case Running = 'running';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Completed = 'completed';
    case Failed = 'failed';
}
