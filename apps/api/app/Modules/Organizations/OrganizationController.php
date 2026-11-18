<?php

namespace App\Modules\Organizations;

use App\Http\PageCursor;
use App\Modules\Audit\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['limit' => ['sometimes', 'integer', 'between:1,100'], 'cursor' => ['sometimes', 'string', 'max:1024']]);
        $organizations = DB::table('organizations')
            ->whereIn('id', DB::table('memberships')->select('organization_id')->where(['user_id' => $request->user()->id, 'active' => true]))
            ->orderBy('id')->cursorPaginate($filters['limit'] ?? 25, ['id', 'name', 'time_zone'], cursor: PageCursor::read($request, $filters['cursor'] ?? null));

        return response()->json(['items' => $organizations->items(), 'next_cursor' => PageCursor::next($request, $organizations->nextCursor())]);
    }

    public function store(Request $request, Membership $memberships, AuditLog $audit): JsonResponse
    {
        abort_unless($request->user()->can_create_organizations, 403);
        $attributes = $request->validate(['name' => ['required', 'string', 'max:200'], 'time_zone' => ['required', 'timezone']]);
        $org = ['id' => (string) Str::uuid(), ...$attributes];
        $actor = $request->user()->id;

        DB::transaction(function () use ($request, $org, $actor, $memberships, $audit): void {
            DB::table('organizations')->insert([...$org, 'created_at' => now()]);
            $membership = $memberships->create($org['id'], $actor, Role::Owner);
            $audit->record($request, $org['id'], $actor, 'organization.created', 'organization', $org['id']);
            $audit->record($request, $org['id'], $actor, 'membership.created', 'membership', $membership);
        });

        return response()->json($org, 201);
    }

    public function memberships(Request $request, string $org, OrganizationAccess $access): JsonResponse
    {
        $access->authorize($request->user(), $org, 'memberships.read');
        $filters = $request->validate(['limit' => ['sometimes', 'integer', 'between:1,100'], 'cursor' => ['sometimes', 'string', 'max:1024']]);
        $page = DB::table('memberships')->where('organization_id', $org)->orderBy('id')
            ->cursorPaginate($filters['limit'] ?? 25, ['id', 'user_id', 'active'], cursor: PageCursor::read($request, $filters['cursor'] ?? null));
        $roles = DB::table('membership_roles')->where('organization_id', $org)
            ->whereIn('membership_id', $page->getCollection()->pluck('id'))->orderBy('role')->get()->groupBy('membership_id');

        return response()->json([
            'items' => $page->getCollection()->map(fn ($row) => [
                'id' => $row->id,
                'user_id' => (string) $row->user_id,
                'active' => (bool) $row->active,
                'roles' => $roles->get($row->id, collect())->pluck('role'),
            ]),
            'next_cursor' => PageCursor::next($request, $page->nextCursor()),
        ]);
    }
}
