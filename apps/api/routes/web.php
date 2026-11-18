<?php

use App\Modules\Audit\AuditController;
use App\Modules\Identity\SessionController;
use App\Modules\Organizations\InvitationController;
use App\Modules\Organizations\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function () {
    Route::get('auth/csrf', [SessionController::class, 'csrf']);
    Route::post('auth/login', [SessionController::class, 'login'])->middleware('throttle:login');
    Route::post('invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:10,1');

    Route::middleware('auth')->group(function () {
        Route::get('auth/me', [SessionController::class, 'show']);
        Route::post('auth/logout', [SessionController::class, 'logout']);
        Route::get('organizations', [OrganizationController::class, 'index']);
        Route::post('organizations', [OrganizationController::class, 'store']);

        Route::prefix('organizations/{org}')->where(['org' => '[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}'])->group(function () {
            Route::get('memberships', [OrganizationController::class, 'memberships']);
            Route::post('invitations', [InvitationController::class, 'store'])->middleware('throttle:20,1');
            Route::get('audit-events', [AuditController::class, 'index']);
        });
    });
});
