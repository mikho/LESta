<?php

namespace App\Enums;

enum IdempotencyReceiptStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
