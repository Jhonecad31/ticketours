<?php

namespace App\Modules\Reservations;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Idempotency
{
    public function run(Request $request, string $org, string $operation, array $data, Closure $action): array
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[!-~]{1,128}$/D', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Proporciona una clave de 1 a 128 caracteres visibles.']);
        }
        $identity = ['organization_id' => $org, 'actor_id' => $request->user()->id, 'operation' => $operation, 'request_key' => $key];
        $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($identity, $fingerprint, $action): array {
            $table = DB::table('idempotent_responses');
            $table->insertOrIgnore([...$identity, 'fingerprint' => $fingerprint]);
            $row = DB::table('idempotent_responses')->where($identity)->lockForUpdate()->first();
            abort_unless(hash_equals($row->fingerprint, $fingerprint), 409, 'IDEMPOTENCY_KEY_REUSED');
            if ($row->response !== null) {
                return json_decode($row->response, true, flags: JSON_THROW_ON_ERROR);
            }

            $result = $action();
            DB::table('idempotent_responses')->where($identity)->update(['response' => json_encode($result, JSON_THROW_ON_ERROR)]);

            return $result;
        });
    }
}
