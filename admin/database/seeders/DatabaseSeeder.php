<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (blank($email) || blank($password)) {
            return;
        }

        if ($password === 'change-me' || strlen($password) < 10) {
            $this->command?->error('ADMIN_PASSWORD is a placeholder or shorter than 10 characters, no admin created.');

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'password' => bcrypt($password),
            ]
        );
    }
}
