<?php

namespace App\Enums;

enum WebServer: string
{
    case Nginx = 'nginx';
    case Apache = 'apache';
}
