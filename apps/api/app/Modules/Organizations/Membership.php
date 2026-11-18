<?php

namespace App\Modules\Organizations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Membership
{
    public function create(string $org, int $userId, Role $role): string
    {
        $id = (string) Str::uuid();
        DB::table('memberships')->insert([
            'id' => $id,
            'organization_id' => $org,
            'user_id' => $userId,
            'active' => true,
            'created_at' => now(),
        ]);
        DB::table('membership_roles')->insert([
            'organization_id' => $org,
            'membership_id' => $id,
            'role' => $role->value,
        ]);

        return $id;
    }
}
