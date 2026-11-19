<?php

namespace App\Modules\Organizations;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrganizationAccess
{
    /** @return Collection<int, Role> */
    public function authorize(User $user, string $org, string $permission): Collection
    {
        $membership = DB::table('memberships')
            ->where(['organization_id' => $org, 'user_id' => $user->id, 'active' => true])
            ->first();

        abort_if($membership === null, 404);

        $roles = DB::table('membership_roles')
            ->where(['organization_id' => $org, 'membership_id' => $membership->id])
            ->pluck('role')
            ->map(Role::from(...));

        $allowed = $roles->contains(fn (Role $role) => $role->allows($permission));
        if (! $allowed && $permission === 'operations.checkin') {
            $allowed = DB::table('checkin_validators')->where(['organization_id' => $org, 'user_id' => $user->id])->exists();
        }
        abort_unless($allowed, 403);

        return $roles;
    }
}
