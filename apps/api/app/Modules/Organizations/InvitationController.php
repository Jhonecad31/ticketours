<?php

namespace App\Modules\Organizations;

use App\Models\User;
use App\Modules\Audit\AuditLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;

class InvitationController
{
    public function store(Request $request, string $org, OrganizationAccess $access, AuditLog $audit): JsonResponse
    {
        $roles = $access->authorize($request->user(), $org, 'memberships.invite');
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'role' => ['required', new Enum(Role::class)]]);
        $role = Role::from($data['role']);
        abort_if($role === Role::Owner || ($role === Role::Admin && ! $roles->contains(Role::Owner)), 403);
        $email = Str::lower($data['email']);
        $id = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        $expiration = now()->addDays(2);

        DB::transaction(function () use ($request, $audit, $org, $role, $email, $id, $token, $expiration): void {
            DB::table('organizations')->where('id', $org)->lockForUpdate()->first();
            abort_if(DB::table('memberships')->where('organization_id', $org)
                ->whereIn('user_id', User::query()->select('id')->where('email', $email))->exists(), 409, 'MEMBER_ALREADY_EXISTS');
            abort_if(DB::table('organization_invitations')->where(['organization_id' => $org, 'email' => $email])
                ->whereNull('accepted_at')->where('expires_at', '>', now())->exists(), 409, 'INVITATION_ALREADY_PENDING');

            DB::table('organization_invitations')->insert([
                'id' => $id,
                'organization_id' => $org,
                'email' => $email,
                'role' => $role->value,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiration,
                'created_at' => now(),
            ]);
            $audit->record($request, $org, $request->user()->id, 'invitation.created', 'invitation', $id);
        });

        return response()->json(['id' => $id, 'token' => $token, 'expires_at' => $expiration->toISOString()], 201);
    }

    public function accept(Request $request, Membership $memberships, AuditLog $audit): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);
        $tokenHash = hash('sha256', $data['token']);
        $org = DB::table('organization_invitations')->where('token_hash', $tokenHash)->value('organization_id');
        abort_if($org === null, 404);

        try {
            $membership = DB::transaction(function () use ($request, $memberships, $audit, $org, $tokenHash): array {
                DB::table('organizations')->where('id', $org)->lockForUpdate()->first();
                $invitation = DB::table('organization_invitations')->where(['organization_id' => $org, 'token_hash' => $tokenHash])
                    ->whereNull('accepted_at')->where('expires_at', '>', now())->lockForUpdate()->first();
                abort_if($invitation === null, 404);

                $user = User::query()->where('email', $invitation->email)->first();
                if ($user !== null) {
                    abort_unless($request->user()?->is($user), $request->user() ? 403 : 401);
                } else {
                    abort_if($request->user() !== null, 403);
                    $account = $request->validate([
                        'name' => ['required', 'string', 'max:200'],
                        'password' => ['required', 'string', 'min:12', function (string $attribute, mixed $value, \Closure $fail): void {
                            if (is_string($value) && strlen($value) > 72) {
                                $fail('The password must be at most 72 bytes.');
                            }
                        }],
                    ]);
                    $user = User::create([...$account, 'email' => $invitation->email]);
                    $audit->record($request, $org, $user->id, 'user.created', 'user', (string) $user->id);
                }

                abort_if(DB::table('memberships')->where(['organization_id' => $org, 'user_id' => $user->id])->exists(), 409, 'MEMBER_ALREADY_EXISTS');
                $role = Role::from($invitation->role);
                $id = $memberships->create($org, $user->id, $role);
                DB::table('organization_invitations')->where(['organization_id' => $org, 'id' => $invitation->id])
                    ->update(['accepted_at' => now()]);
                $audit->record($request, $org, $user->id, 'invitation.accepted', 'invitation', $invitation->id);
                $audit->record($request, $org, $user->id, 'membership.created', 'membership', $id);

                return ['id' => $id, 'user_id' => (string) $user->id, 'active' => true, 'roles' => [$role->value]];
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'MEMBER_ALREADY_EXISTS');
        }

        return response()->json($membership, 201);
    }
}
