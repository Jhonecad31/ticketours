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
            self::Owner, self::Admin => in_array($permission, [
                'memberships.read', 'memberships.invite', 'audit.read',
                'catalog.read', 'catalog.write', 'catalog.publish',
                'inventory.read', 'inventory.write', 'pricing.read', 'pricing.write',
                'customers.read', 'customers.write', 'reservations.quote', 'reservations.hold', 'reservations.create',
                'reservations.read', 'reservations.confirm', 'reservations.cancel', 'reservations.settings',
            ], true),
            self::BookingAgent => in_array($permission, [
                'catalog.read', 'inventory.read', 'pricing.read', 'customers.read', 'customers.write',
                'reservations.quote', 'reservations.hold', 'reservations.create', 'reservations.read',
            ], true),
        };
    }
}
