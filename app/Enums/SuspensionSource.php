<?php

namespace App\Enums;

/**
 * Who suspended something, which decides who may lift it: the account's own owner (Manual), the
 * reseller that manages the account (Reseller), the provider running the platform (Provider), or
 * the suspension of the parent it belongs to (Cascade, lifted with that parent). A suspension can
 * only be lifted at its own level or a higher one, so an owner cannot undo what the provider did.
 */
enum SuspensionSource: string
{
    case Manual = 'manual';
    case Cascade = 'cascade';
    case Reseller = 'reseller';
    case Provider = 'provider';

    /**
     * How far up a suspension was imposed. Cascade has no rank of its own.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Manual, self::Cascade => 1,
            self::Reseller => 2,
            self::Provider => 3,
        };
    }
}
