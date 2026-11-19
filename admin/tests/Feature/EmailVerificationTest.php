<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unverified_user_is_held_at_the_verification_page(): void
    {
        $user = User::factory()->unverified()->withTwoFactor()->create();

        foreach (['/dashboard', '/two-factor/setup', '/domains'] as $url) {
            $this->actingAs($user)->get($url)->assertRedirect('/email/verify');
        }
        $this->get('/email/verify')->assertOk();
    }

    public function test_the_signed_link_verifies_the_address(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($url)->assertRedirect('/dashboard');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_link_for_another_user_does_not_verify(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $other->id, 'hash' => sha1($other->email)]);

        $this->actingAs($user)->get($url)->assertForbidden();

        $this->assertNull($other->fresh()->email_verified_at);
    }

    public function test_the_link_can_be_sent_again(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post('/email/verification-notification')->assertRedirect();

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
