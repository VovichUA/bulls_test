<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Set a valid ADMIN_EMAIL before running AdminSeeder.');
        }
        DB::transaction(function () use ($email, $password): void {
            if (User::where('is_admin', true)->where('email', '!=', $email)->exists()) {
                throw new RuntimeException('An administrator already exists with another email.');
            }
            $admin = User::where('email', $email)->first();
            if ($admin && ! $admin->is_admin) {
                throw new RuntimeException('ADMIN_EMAIL belongs to an existing non-admin account; choose another email.');
            }
            if (! $admin) {
                if (! is_string($password) || mb_strlen($password) < 12) {
                    throw new RuntimeException('Set ADMIN_PASSWORD with at least 12 characters to create an administrator.');
                }
                $admin = User::create(['name' => config('admin.name'), 'email' => $email, 'password' => $password]);
            }
            $admin->forceFill(['is_admin' => true, 'approved_at' => $admin->approved_at ?? now()])->save();
        });
    }
}
