<?php

namespace App\Modules\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditLog
{
    public function record(Request $request, string $org, int $actor, string $action, string $entity, string $id): void
    {
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $org,
            'actor_id' => $actor,
            'action' => $action,
            'entity_type' => $entity,
            'entity_id' => $id,
            'occurred_at' => now(),
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]);
    }
}
