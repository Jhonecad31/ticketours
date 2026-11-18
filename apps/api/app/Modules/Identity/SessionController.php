<?php

namespace App\Modules\Identity;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SessionController
{
    public function csrf(Request $request): JsonResponse
    {
        return response()->json(['token' => $request->session()->token()]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string', 'max:128']]);
        $credentials['email'] = Str::lower($credentials['email']);
        abort_if(strlen($credentials['password']) > 72, 401);

        abort_unless(Auth::attempt($credentials), 401);

        $request->session()->regenerate();

        return $this->show($request);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json([...$request->user()->only('name', 'email'), 'id' => (string) $request->user()->id, 'can_create_organizations' => (bool) $request->user()->can_create_organizations]);
    }

    public function logout(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
