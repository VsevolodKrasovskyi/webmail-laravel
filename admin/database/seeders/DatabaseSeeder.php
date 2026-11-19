<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Nothing to seed: the first user to register (or sign in with Google) becomes the
     * administrator, other users join by invitation.
     */
    public function run(): void {}
}
