<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reset_link_is_sent_and_the_answer_is_the_same_for_unknown_addresses(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ann@example.com']);

        $known = $this->post('/forgot-password', ['email' => 'ann@example.com']);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
        $this->assertSame(session('status'), 'If this address has an account, a reset link is on its way.');
        $known->assertSessionHas('status');
        $unknown->assertSessionHas('status');
    }

    public function test_the_password_is_reset_with_the_token_and_the_address_gets_verified(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'ann@example.com', 'password' => 'old-password-123']);
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'ann@example.com',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertRedirect('/login');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-456', $user->password));
        $this->assertNotNull($user->email_verified_at);

        // The token is single-use.
        $this->post('/reset-password', [
            'token' => $token, 'email' => 'ann@example.com',
            'password' => 'another-password-789', 'password_confirmation' => 'another-password-789',
        ])->assertSessionHasErrors('email');
    }

    public function test_a_google_only_user_can_set_a_first_password_this_way(): void
    {
        $user = User::factory()->create(['email' => 'g@example.com', 'password' => null]);

        $this->post('/reset-password', [
            'token' => Password::createToken($user), 'email' => 'g@example.com',
            'password' => 'first-password-123', 'password_confirmation' => 'first-password-123',
        ])->assertRedirect('/login');

        $this->assertTrue(Hash::check('first-password-123', $user->fresh()->password));
    }

    public function test_a_short_password_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'ann@example.com']);

        $this->post('/reset-password', [
            'token' => Password::createToken($user), 'email' => 'ann@example.com',
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }

    public function test_a_wrong_token_is_refused(): void
    {
        User::factory()->create(['email' => 'ann@example.com']);

        $this->post('/reset-password', [
            'token' => 'wrong', 'email' => 'ann@example.com',
            'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
        ])->assertSessionHasErrors('email');
    }
}
