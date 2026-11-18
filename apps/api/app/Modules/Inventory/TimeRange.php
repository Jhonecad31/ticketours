<?php

namespace App\Modules\Inventory;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class TimeRange
{
    public const TIMESTAMP = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D';

    public function __construct(public readonly string $start, public readonly string $end) {}

    public static function read(Request $request, string $startKey = 'start_at', string $endKey = 'end_at'): self
    {
        $data = $request->validate([
            $startKey => ['required', 'string', self::TIMESTAMP, 'date'],
            $endKey => ['required', 'string', self::TIMESTAMP, 'date', 'after:'.$startKey],
        ]);
        $start = Carbon::parse($data[$startKey])->utc();
        $end = Carbon::parse($data[$endKey])->utc();
        if ($end->greaterThan($start->copy()->addDays(93))) {
            throw ValidationException::withMessages([$endKey => 'El intervalo máximo es de 93 días.']);
        }

        return new self($start->toDateTimeString(), $end->toDateTimeString());
    }

    public static function utc(string $date): string
    {
        return Carbon::parse($date, 'UTC')->utc()->toISOString();
    }
}
