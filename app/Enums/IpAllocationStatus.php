<?php

namespace App\Enums;

enum IpAllocationStatus: string
{
    case Shared = 'shared';
    case Dedicated = 'dedicated';
}
