<?php

namespace App\Http;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaginatedJson
{
    public static function from(Request $request, Builder $query, array $columns, ?Closure $present = null): JsonResponse
    {
        $page = $request->validate(['limit' => ['sometimes', 'integer', 'between:1,100'], 'cursor' => ['sometimes', 'string', 'max:1024']]);
        $results = $query->orderBy('id')->cursorPaginate($page['limit'] ?? 25, $columns, cursor: PageCursor::read($request, $page['cursor'] ?? null));

        return response()->json([
            'items' => $present === null ? $results->items() : $results->getCollection()->map($present),
            'next_cursor' => PageCursor::next($request, $results->nextCursor()),
        ]);
    }
}
