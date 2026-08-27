<?php

namespace App\Enums;

enum SslMode: string
{
    case None = 'none';
    case Manual = 'manual';
    case LetsEncrypt = 'lets_encrypt';
}
