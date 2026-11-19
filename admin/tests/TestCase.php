<?php

namespace Tests;

use App\Http\Middleware\EnsureTwoFactor;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** A signed-in administrator (listed in ADMIN_EMAIL) who is past verification and two-factor authentication. */
    protected function actingAsAdmin(): static
    {
        $user = User::factory()->withTwoFactor()->create();
        config(['auth.admin_emails' => [...config('auth.admin_emails'), strtolower($user->email)]]);

        return $this->actingAsFullyAuthenticated($user);
    }

    /** A signed-in user (not an administrator) who is past verification and two-factor authentication. */
    protected function actingAsFullyAuthenticated(User $user): static
    {
        return $this->actingAs($user)->withSession([EnsureTwoFactor::SESSION_KEY => true]);
    }
}
