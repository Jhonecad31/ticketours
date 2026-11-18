<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PhaseOneTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1';

    public function test_session_login_and_logout_and_csrf(): void
    {
        $user = User::factory()->create(['email' => 'test@example.test', 'password' => 'a-test-password']);
        $this->get(self::API.'/auth/me')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->app->detectEnvironment(fn () => 'local');
        $csrf = $this->getJson(self::API.'/auth/csrf')->assertOk()->json('token');
        $credentials = ['email' => 'TEST@example.test', 'password' => 'a-test-password'];
        $this->postJson(self::API.'/auth/login', $credentials)->assertStatus(419)->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
        $this->withSession(['_token' => $csrf])
            ->postJson(self::API.'/auth/login', $credentials, ['X-CSRF-TOKEN' => $csrf])
            ->assertOk()->assertJsonPath('id', (string) $user->id);
        $this->getJson(self::API.'/auth/me')->assertOk();

        $newToken = $this->getJson(self::API.'/auth/csrf')->assertOk()->json('token');
        $this->assertNotSame($csrf, $newToken);
        $this->withSession(['_token' => $newToken])
            ->postJson(self::API.'/auth/logout', [], ['X-CSRF-TOKEN' => $newToken])->assertNoContent();
        $this->assertGuest();
    }

    public function test_organization_isolation_roles_and_audit(): void
    {
        $platform = User::factory()->create();
        $platform->forceFill(['can_create_organizations' => true])->save();
        $member = User::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->postJson(self::API.'/organizations', ['name' => 'Denied', 'time_zone' => 'America/Cancun'])->assertForbidden();
        $this->actingAs($platform);
        $north = $this->organization('Norte')->assertCreated();
        $south = $this->organization('Sur')->assertCreated();
        $northPath = self::API.'/organizations/'.$north->json('id');
        $southPath = self::API.'/organizations/'.$south->json('id');
        $adminToken = $this->invite($northPath, $member, 'admin')->assertCreated()->json('token');
        $agentToken = $this->invite($southPath, $member, 'booking_agent')->assertCreated()->json('token');

        $this->actingAs($outsider)->getJson(self::API.'/organizations')->assertJsonCount(0, 'items');
        $this->getJson("$northPath/memberships")->assertNotFound();
        $this->getJson("$southPath/audit-events")->assertNotFound();
        $this->accept($adminToken)->assertForbidden();

        $this->actingAs($member);
        $this->accept($adminToken)->assertCreated()->assertJsonPath('roles.0', 'admin');
        $this->getJson("$southPath/memberships")->assertNotFound();
        $this->accept($agentToken)->assertCreated()->assertJsonPath('roles.0', 'booking_agent');
        $this->accept($agentToken)->assertNotFound();
        $this->getJson(self::API.'/organizations')->assertJsonCount(2, 'items');
        $cursor = $this->getJson(self::API.'/organizations?limit=1')->assertOk()->assertJsonCount(1, 'items')->json('next_cursor');
        $this->getJson(self::API.'/organizations?limit=1&cursor='.urlencode($cursor))->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('next_cursor', null);
        $this->getJson("$northPath/memberships?cursor=".urlencode($cursor))->assertUnprocessable();
        $this->getJson("$northPath/memberships")->assertOk()->assertJsonCount(2, 'items');
        $denial = $this->getJson("$southPath/memberships")->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $denial->assertHeader('X-Correlation-ID', $denial->json('correlation_id'));
        $this->getJson("$southPath/audit-events")->assertForbidden();
        $this->invite($northPath, $outsider, 'admin')->assertForbidden();
        $this->getJson("$northPath/audit-events")->assertOk()->assertJsonCount(5, 'items')
            ->assertJsonFragment(['action' => 'organization.created', 'correlation_id' => $north->headers->get('X-Correlation-ID')]);
        $this->assertDatabaseHas('audit_events', ['organization_id' => $south->json('id'), 'actor_id' => $member->id, 'action' => 'invitation.accepted']);
    }

    public function test_guest_can_create_an_account_only_with_an_active_invitation(): void
    {
        $platform = User::factory()->create();
        $platform->forceFill(['can_create_organizations' => true])->save();
        $this->actingAs($platform);
        $org = $this->organization('Norte')->assertCreated()->json('id');
        $scope = self::API.'/organizations/'.$org;
        $invitee = new User(['email' => 'invited@example.test']);
        $expired = new User(['email' => 'expired@example.test']);

        $invitation = $this->invite($scope, $invitee, 'booking_agent')->assertCreated();
        $token = $invitation->json('token');
        $this->invite($scope, $invitee, 'booking_agent')->assertConflict()->assertJsonPath('code', 'INVITATION_ALREADY_PENDING');
        $this->assertDatabaseHas('organization_invitations', ['id' => $invitation->json('id'), 'token_hash' => hash('sha256', $token)]);
        $expiredToken = $this->invite($scope, $expired, 'booking_agent')->assertCreated()->json('token');
        DB::table('organization_invitations')->where('token_hash', hash('sha256', $expiredToken))->update(['expires_at' => now()->subSecond()]);

        Auth::forgetGuards();
        $this->accept($expiredToken)->assertNotFound();
        $this->accept($token)->assertUnprocessable();
        $this->postJson(self::API.'/invitations/accept', ['token' => $token, 'name' => 'Invitada', 'password' => 'testing-password'])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'invited@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'expired@example.test']);
        $this->assertDatabaseHas('audit_events', ['organization_id' => $org, 'action' => 'user.created']);
    }

    public function test_database_rejects_a_role_referencing_a_membership_in_another_organization(): void
    {
        $platform = User::factory()->create();
        $platform->forceFill(['can_create_organizations' => true])->save();
        $this->actingAs($platform);
        $north = $this->organization('Norte')->assertCreated()->json('id');
        $south = $this->organization('Sur')->assertCreated()->json('id');
        $membership = DB::table('memberships')->where('organization_id', $north)->value('id');

        $this->expectException(QueryException::class);
        DB::table('membership_roles')->insert(['organization_id' => $south, 'membership_id' => $membership, 'role' => 'admin']);
    }

    private function organization(string $name): TestResponse
    {
        return $this->postJson(self::API.'/organizations', ['name' => $name, 'time_zone' => 'America/Cancun']);
    }

    private function invite(string $scope, User $user, string $role): TestResponse
    {
        return $this->postJson("$scope/invitations", ['email' => $user->email, 'role' => $role]);
    }

    private function accept(string $token): TestResponse
    {
        return $this->postJson(self::API.'/invitations/accept', ['token' => $token]);
    }
}
