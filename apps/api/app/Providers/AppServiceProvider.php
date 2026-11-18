<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $input = $request->input('email');
            $email = is_string($input) ? Str::lower($input) : '';

            return [
                Limit::perMinute(25)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('account:'.hash('sha256', $email).'@'.$request->ip()),
            ];
        });
    }
}
