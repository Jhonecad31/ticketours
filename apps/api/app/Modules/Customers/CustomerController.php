<?php

namespace App\Modules\Customers;

use App\Http\PaginatedJson;
use App\Modules\Audit\AuditLog;
use App\Modules\Organizations\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerController
{
    public function index(Request $request, string $org, OrganizationAccess $access): JsonResponse
    {
        $access->authorize($request->user(), $org, 'customers.read');

        return PaginatedJson::from($request, DB::table('customers')->where('organization_id', $org), ['id', 'name', 'email']);
    }

    public function store(Request $request, string $org, OrganizationAccess $access, AuditLog $audit): JsonResponse
    {
        $access->authorize($request->user(), $org, 'customers.write');
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'email' => ['nullable', 'email', 'max:254']]);
        $record = ['id' => (string) Str::uuid(), ...$data];

        DB::transaction(function () use ($request, $org, $audit, $record): void {
            DB::table('customers')->insert(['organization_id' => $org, ...$record]);
            $audit->record($request, $org, $request->user()->id, 'customer.created', 'customer', $record['id']);
        });

        return response()->json($record, 201);
    }
}
