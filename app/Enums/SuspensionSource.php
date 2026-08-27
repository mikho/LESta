<?php

namespace App\Enums;

enum SuspensionSource: string
{
    case Manual = 'manual';
    case Cascade = 'cascade';
}
