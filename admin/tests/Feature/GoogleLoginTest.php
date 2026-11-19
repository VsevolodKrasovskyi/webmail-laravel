<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private function googleReturns(string $email, bool $verified = true): void
    {
        $user = (new SocialiteUser)->map(['id' => 'g-1', 'name' => 'Ada', 'email' => $email, 'avatar' => 'https://lh3.googleusercontent.com/a/photo=s96']);
        $user->user = ['email_verified' => $verified];

        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_the_first_google_user_becomes_the_administrator(): void
    {
        $this->googleReturns('Boss@Example.com');

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'boss@example.com', 'google_id' => 'g-1', 'is_admin' => true, 'avatar' => 'https://lh3.googleusercontent.com/a/photo=s96']);
        $this->assertNotNull(User::where('email', 'boss@example.com')->first()->email_verified_at);
    }

    public function test_a_stranger_is_rejected_once_there_is_a_user(): void
    {
        User::factory()->admin()->create();
        $this->googleReturns('stranger@example.com');

        $this->get('/auth/google/callback')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(1, User::count());
    }

    public function test_an_unverified_google_email_is_rejected_even_on_first_run(): void
    {
        $this->googleReturns('boss@example.com', verified: false);

        $this->get('/auth/google/callback')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_existing_user_can_sign_in_with_google_and_gets_verified_but_stays_a_plain_user(): void
    {
        User::factory()->admin()->create();
        $user = User::factory()->unverified()->create(['email' => 'staff@example.com']);
        $this->googleReturns('staff@example.com');

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame('g-1', $user->fresh()->google_id);
        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_google_sign_in_needs_no_code_until_two_factor_is_turned_on(): void
    {
        $this->googleReturns('boss@example.com');

        $this->get('/auth/google/callback');
        $this->get('/dashboard')->assertOk();
    }

    public function test_google_sign_in_asks_for_the_code_once_it_is_on(): void
    {
        User::factory()->withTwoFactor()->create(['email' => 'boss@example.com']);
        $this->googleReturns('boss@example.com');

        $this->get('/auth/google/callback');
        $this->get('/dashboard')->assertRedirect('/two-factor/challenge');
    }
}
