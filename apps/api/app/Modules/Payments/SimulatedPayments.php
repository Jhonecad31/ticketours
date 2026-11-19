<?php

namespace App\Modules\Payments;

use Illuminate\Support\Str;

class SimulatedPayments
{
    public function approve(): string
    {
        return (string) Str::uuid();
    }
}
