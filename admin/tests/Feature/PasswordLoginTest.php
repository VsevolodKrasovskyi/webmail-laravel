<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_signs_in_with_email_and_password(): void
    {
        $user = User::factory()->withTwoFactor()->create(['email' => 'ann@example.com', 'password' => 'correct-horse-battery']);

        $this->post('/login', ['email' => 'Ann@Example.com', 'password' => 'correct-horse-battery'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        // Signed in, but not past two-factor authentication yet.
        $this->get('/dashboard')->assertRedirect('/two-factor/challenge');
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'ann@example.com', 'password' => 'correct-horse-battery']);

        $this->post('/login', ['email' => 'ann@example.com', 'password' => 'nope-nope-nope'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_unknown_address_and_a_google_only_user_get_the_same_answer(): void
    {
        User::factory()->create(['email' => 'g@example.com', 'password' => null]);

        $a = $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'whatever-123']);
        $b = $this->post('/login', ['email' => 'g@example.com', 'password' => 'whatever-123']);

        $a->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $b->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest();
    }

    public function test_repeated_failures_are_throttled(): void
    {
        User::factory()->create(['email' => 'ann@example.com', 'password' => 'correct-horse-battery']);

        foreach (range(1, 5) as $i) {
            $this->post('/login', ['email' => 'ann@example.com', 'password' => 'bad-password-1']);
        }

        // Even the right password is refused while the limit holds.
        $this->post('/login', ['email' => 'ann@example.com', 'password' => 'correct-horse-battery'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
