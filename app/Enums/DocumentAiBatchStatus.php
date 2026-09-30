<?php

namespace App\Enums;

enum DocumentAiBatchStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
