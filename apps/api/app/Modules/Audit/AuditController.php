<?php

namespace App\Modules\Audit;

use App\Http\PageCursor;
use App\Modules\Organizations\OrganizationAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AuditController
{
    public function index(Request $request, string $org, OrganizationAccess $access): JsonResponse
    {
        $access->authorize($request->user(), $org, 'audit.read');
        $filters = $request->validate([
            'actor_id' => ['sometimes', 'integer', 'min:1'],
            'action' => ['sometimes', 'string', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', ...($request->filled('from') ? ['after:from'] : [])],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
        ]);
        $events = DB::table('audit_events')
            ->where('organization_id', $org)
            ->when(isset($filters['actor_id']), fn (Builder $query) => $query->where('actor_id', $filters['actor_id']))
            ->when(isset($filters['action']), fn (Builder $query) => $query->where('action', $filters['action']))
            ->when(isset($filters['from']), fn (Builder $query) => $query->where('occurred_at', '>=', Carbon::parse($filters['from'])->utc()->toDateTimeString()))
            ->when(isset($filters['to']), fn (Builder $query) => $query->where('occurred_at', '<', Carbon::parse($filters['to'])->utc()->toDateTimeString()))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->cursorPaginate($filters['limit'] ?? 25, ['id', 'actor_id', 'action', 'entity_type', 'entity_id', 'occurred_at', 'correlation_id'], cursor: PageCursor::read($request, $filters['cursor'] ?? null));

        return response()->json([
            'items' => $events->getCollection()->map(fn ($event) => [
                ...((array) $event),
                'actor_id' => (string) $event->actor_id,
                'occurred_at' => Carbon::parse($event->occurred_at, 'UTC')->toISOString(),
            ]),
            'next_cursor' => PageCursor::next($request, $events->nextCursor()),
        ]);
    }
}
