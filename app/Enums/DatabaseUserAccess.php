<?php

namespace App\Enums;

/**
 * What an extra database user may do on its database: full is the
 * database's own user's grant, read_only the statistics account's
 * (SELECT and SHOW VIEW). The agent maps each to its GRANT.
 */
enum DatabaseUserAccess: string
{
    case Full = 'full';
    case ReadOnly = 'read_only';
}
