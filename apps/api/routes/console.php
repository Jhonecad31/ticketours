<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

Artisan::command('ticketours:platform-user {email} {--name=}', function (): int {
    $password = $this->secret('Contraseña nueva');
    $validator = Validator::make([
        'name' => $this->option('name') ?? $this->ask('Nombre'),
        'email' => Str::lower(trim($this->argument('email'))),
        'password' => $password,
    ], [
        'name' => ['required', 'string', 'max:200'],
        'email' => ['required', 'email', 'max:254', 'unique:users,email'],
        'password' => ['required', 'string', 'min:12'],
    ]);
    if ($validator->fails() || strlen($password ?? '') > 72) {
        $this->error($validator->errors()->first() ?: 'La contraseña admite hasta 72 bytes.');

        return 1;
    }

    DB::transaction(function () use ($validator): void {
        $user = new User($validator->validated());
        $user->can_create_organizations = true;
        $user->save();

        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => null,
            'actor_id' => $user->id,
            'action' => 'platform_user.provisioned',
            'entity_type' => 'user',
            'entity_id' => (string) $user->id,
            'occurred_at' => now(),
            'correlation_id' => (string) Str::uuid(),
        ]);
    });

    $this->info('Cuenta de plataforma creada.');

    return 0;
})->purpose('Crear una cuenta autorizada para dar de alta organizaciones');
