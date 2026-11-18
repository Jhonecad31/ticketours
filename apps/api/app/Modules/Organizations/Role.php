<?php

namespace App\Modules\Organizations;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case BookingAgent = 'booking_agent';

    public function allows(string $permission): bool
    {
        return match ($this) {
            self::Owner, self::Admin => in_array($permission, ['memberships.read', 'memberships.invite', 'audit.read'], true),
            self::BookingAgent => false,
        };
    }
}
