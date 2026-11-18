<?php

namespace App\Modules\Reservations;

use App\Modules\Audit\AuditLog;
use App\Modules\Organizations\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationSettingsController
{
    public function show(Request $request, string $org, OrganizationAccess $access): JsonResponse
    {
        $access->authorize($request->user(), $org, 'reservations.settings');

        return response()->json(['hold_minutes' => (int) DB::table('organizations')->where('id', $org)->value('hold_minutes')]);
    }

    public function update(Request $request, string $org, OrganizationAccess $access, AuditLog $audit): JsonResponse
    {
        $access->authorize($request->user(), $org, 'reservations.settings');
        $data = $request->validate(['hold_minutes' => ['required', 'integer', 'between:1,1440']]);
        $settings = ['hold_minutes' => (int) $data['hold_minutes']];
        DB::transaction(function () use ($request, $org, $audit, $settings): void {
            DB::table('organizations')->where('id', $org)->update($settings);
            $audit->record($request, $org, $request->user()->id, 'reservation_settings.updated', 'organization', $org);
        });

        return response()->json($settings);
    }
}
