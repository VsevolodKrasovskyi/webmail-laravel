<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    public function test_a_user_without_two_factor_works_normally(): void
    {
        $user = User::factory()->create();
        config(['auth.admin_emails' => [$user->email]]);

        foreach (['/dashboard', '/domains', '/mailboxes', '/aliases', '/users'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_setup_confirms_the_first_code_and_shows_recovery_codes_once(): void
    {
        $user = User::factory()->create();

        $page = $this->actingAs($user)->get('/two-factor/setup');
        $page->assertOk()->assertInertia(fn ($p) => $p->component('Auth/TwoFactorSetup')->has('secret')->has('otpauthUri'));

        $secret = $user->fresh()->two_factor_secret;
        $this->assertFalse($user->fresh()->hasTwoFactor());

        $this->post('/two-factor/setup', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasTwoFactor());

        $this->post('/two-factor/setup', ['code' => Totp::code($secret, Totp::step())])->assertRedirect('/two-factor/setup');

        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->get('/two-factor/setup')->assertInertia(fn ($p) => $p->has('recoveryCodes', 8));
        // A reload shows the status page instead, and the user is already through.
        $this->get('/two-factor/setup')->assertInertia(fn ($p) => $p->where('enabled', true)->where('recoveryCodes', null));
        $this->get('/dashboard')->assertOk();
    }

    public function test_the_secret_is_not_stored_in_plain_text(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->assertNotSame(self::SECRET, \DB::table('users')->where('id', $user->id)->value('two_factor_secret'));
    }

    public function test_the_challenge_accepts_a_current_code_once(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/two-factor/challenge');

        $this->post('/two-factor/challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->get('/dashboard')->assertRedirect('/two-factor/challenge');

        $code = Totp::code(self::SECRET, Totp::step());
        $this->post('/two-factor/challenge', ['code' => $code])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();

        // The same code from another session is a replay.
        auth()->logout();
        $this->flushSession();
        $this->actingAs($user->fresh())->post('/two-factor/challenge', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $hash = hash_hmac('sha256', 'abcde-fghij', (string) config('app.key'));
        $user->forceFill(['two_factor_recovery_codes' => [$hash]])->save();

        $this->actingAs($user)->post('/two-factor/challenge', ['recovery_code' => 'ABCDE-fghij '])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);

        $this->flushSession();
        $this->actingAs($user->fresh())->post('/two-factor/challenge', ['recovery_code' => 'abcde-fghij'])
            ->assertSessionHasErrors('recovery_code');
    }

    public function test_the_challenge_is_throttled(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->actingAs($user);

        foreach (range(1, 6) as $i) {
            $this->post('/two-factor/challenge', ['code' => '000000']);
        }

        $this->post('/two-factor/challenge', ['code' => Totp::code(self::SECRET, Totp::step())])->assertStatus(429);
    }

    public function test_it_can_be_turned_off_with_a_current_code_only(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->actingAsFullyAuthenticated($user);

        $this->post('/two-factor/disable', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertTrue($user->fresh()->hasTwoFactor());

        $this->post('/two-factor/disable', ['code' => Totp::code(self::SECRET, Totp::step())])->assertRedirect('/two-factor/setup');

        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_a_session_without_the_code_cannot_manage_or_turn_off_two_factor(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)->get('/two-factor/setup')->assertRedirect('/two-factor/challenge');
        $this->post('/two-factor/disable', ['code' => Totp::code(self::SECRET, Totp::step())])->assertRedirect('/two-factor/setup');
        $this->assertTrue($user->fresh()->hasTwoFactor());
    }

    public function test_an_admin_can_reset_a_users_two_factor(): void
    {
        $target = User::factory()->withTwoFactor()->create();

        $this->actingAsAdmin()->post("/users/{$target->id}/two-factor-reset")->assertRedirect();

        $this->assertFalse($target->fresh()->hasTwoFactor());
        $this->assertNull($target->fresh()->two_factor_secret);
    }
}
