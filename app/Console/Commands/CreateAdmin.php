<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Admins are never created through the public sign-up form. This makes (or
 * updates) the one from ADMIN_EMAIL and ADMIN_PASSWORD, and is safe to run on
 * every deploy.
 */
#[Signature('pixelforge:admin')]
#[Description('Create or update the admin account from ADMIN_EMAIL and ADMIN_PASSWORD')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $email = config('pixelforge.admin.email');
        $password = config('pixelforge.admin.password');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            $this->components->error('Set ADMIN_EMAIL and ADMIN_PASSWORD first.');

            return self::FAILURE;
        }

        $admin = User::query()->firstOrNew(['email' => $email]);
        $admin->forceFill([
            'name' => $admin->name ?: 'Admin',
            'password' => $password,
            'is_admin' => true,
            'email_verified_at' => $admin->email_verified_at ?? now(),
        ])->save();

        $this->components->info("Admin account ready for {$email}.");

        return self::SUCCESS;
    }
}
