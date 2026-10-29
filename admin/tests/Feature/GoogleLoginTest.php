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
        $user = (new SocialiteUser())->map(['id' => 'g-1', 'name' => 'Ada', 'email' => $email, 'avatar' => 'https://lh3.googleusercontent.com/a/photo=s96']);
        $user->user = ['email_verified' => $verified];

        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_a_listed_admin_is_created_and_signed_in(): void
    {
        config(['auth.admin_emails' => ['boss@example.com']]);
        $this->googleReturns('Boss@Example.com');

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'boss@example.com', 'google_id' => 'g-1', 'avatar' => 'https://lh3.googleusercontent.com/a/photo=s96']);

        // The session keeps working on later requests with no password column at all.
        $this->get('/dashboard')->assertOk();
        $this->get('/domains')->assertOk();
    }

    public function test_an_unlisted_account_is_rejected(): void
    {
        config(['auth.admin_emails' => ['boss@example.com']]);
        $this->googleReturns('stranger@example.com');

        $this->get('/auth/google/callback')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_unverified_google_email_is_rejected(): void
    {
        config(['auth.admin_emails' => ['boss@example.com']]);
        $this->googleReturns('boss@example.com', verified: false);

        $this->get('/auth/google/callback')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_there_is_no_password_login(): void
    {
        $this->post('/login', ['email' => 'a@b.com', 'password' => 'whatever-123'])->assertStatus(405);
    }
}
