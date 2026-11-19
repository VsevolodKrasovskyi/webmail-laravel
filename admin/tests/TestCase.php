<?php

namespace Tests;

use App\Http\Middleware\EnsureTwoFactor;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** A signed-in administrator who is past verification and two-factor authentication. */
    protected function actingAsAdmin(): static
    {
        return $this->actingAsFullyAuthenticated(User::factory()->admin()->withTwoFactor()->create());
    }

    /** A signed-in user (not an administrator) who is past verification and two-factor authentication. */
    protected function actingAsFullyAuthenticated(User $user): static
    {
        return $this->actingAs($user)->withSession([EnsureTwoFactor::SESSION_KEY => true]);
    }
}
