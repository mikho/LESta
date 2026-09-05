<?php

namespace App\Enums;

enum NodeEnrollmentStatus: string
{
    case Pending = 'pending';
    case Enrolled = 'enrolled';
    case Revoked = 'revoked';
}
