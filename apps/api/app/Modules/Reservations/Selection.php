<?php

namespace App\Modules\Reservations;

use App\Modules\Inventory\TimeRange;
use Illuminate\Support\Facades\Validator;

final class Selection
{
    public static function normalize(array $input): array
    {
        if (array_key_exists('session_id', $input)) {
            $data = Validator::make($input, ['session_id' => ['required', 'uuid'], 'quantity' => ['required', 'integer', 'between:1,2147483647'], 'resource_id' => ['missing'], 'start_at' => ['missing'], 'end_at' => ['missing']])->validate();

            return ['session_id' => strtolower($data['session_id']), 'quantity' => (int) $data['quantity']];
        }

        $data = Validator::make($input, [
            'resource_id' => ['required', 'uuid'],
            'start_at' => ['required', 'string', TimeRange::TIMESTAMP, 'date'],
            'end_at' => ['required', 'string', TimeRange::TIMESTAMP, 'date', 'after:start_at'],
            'quantity' => ['missing'],
        ])->validate();

        return [
            'resource_id' => strtolower($data['resource_id']),
            'start_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($data['start_at'])),
            'end_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($data['end_at'])),
        ];
    }
}
