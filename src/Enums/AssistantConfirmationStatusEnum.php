<?php

namespace SchoolAid\Assistant\Enums;

enum AssistantConfirmationStatusEnum: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Failed = 'failed';
}
