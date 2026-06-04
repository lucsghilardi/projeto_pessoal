<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $adminName = trim((string) env('ADMIN_NAME', ''));
        $adminEmail = Str::lower(trim((string) env('ADMIN_EMAIL', '')));
        $adminPassword = (string) env('ADMIN_PASSWORD', '');

        if ($adminName === '' || $adminEmail === '' || $adminPassword === '') {
            return;
        }

        if (! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('ADMIN_EMAIL must be a valid email address.');
        }

        if (strlen($adminPassword) < 12) {
            throw new RuntimeException('ADMIN_PASSWORD must have at least 12 characters.');
        }

        DB::table('users')->upsert(
            [[
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => Hash::make($adminPassword),
                'role' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['email'],
            ['name', 'password', 'role', 'updated_at']
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $adminEmail = Str::lower(trim((string) env('ADMIN_EMAIL', '')));

        if ($adminEmail === '') {
            return;
        }

        DB::table('users')
            ->where('email', $adminEmail)
            ->delete();
    }
};
