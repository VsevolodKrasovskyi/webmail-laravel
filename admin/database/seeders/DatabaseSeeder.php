<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Nothing to seed: admins are created on their first Google sign-in
     * (see ADMIN_EMAIL in config/auth.php).
     */
    public function run(): void {}
}
