<?php

namespace App\Enums;

enum DnsRecordType: string
{
    case A = 'A';
    case AAAA = 'AAAA';
    case NS = 'NS';
    case CNAME = 'CNAME';
    case MX = 'MX';
    case TXT = 'TXT';
    case SRV = 'SRV';
    case PTR = 'PTR';
    case CAA = 'CAA';
}
