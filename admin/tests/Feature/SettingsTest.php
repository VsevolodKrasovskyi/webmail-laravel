<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_shows_the_account(): void
    {
        $user = User::factory()->create(['name' => 'Ann', 'password' => null]);

        $this->actingAs($user)->get('/settings')->assertInertia(fn ($p) => $p->component('Settings')
            ->where('profile.name', 'Ann')->where('hasPassword', false)->where('twoFactor', false));
    }

    public function test_the_name_can_be_changed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/profile', ['name' => 'New Name'])->assertSessionHas('success');
        $this->put('/settings/profile', ['name' => ''])->assertSessionHasErrors('name');

        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_the_password_needs_the_current_one(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $new = ['password' => 'new-password-456', 'password_confirmation' => 'new-password-456'];

        $this->actingAs($user)->put('/settings/password', $new + ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));

        $this->put('/settings/password', $new + ['current_password' => 'old-password-123'])->assertSessionHas('success');
        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));
    }

    public function test_a_google_only_user_sets_a_first_password(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->actingAs($user)->put('/settings/password', ['password' => 'first-password-123', 'password_confirmation' => 'first-password-123'])
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('first-password-123', $user->fresh()->password));
    }

    public function test_a_short_password_is_refused(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->actingAs($user)->put('/settings/password', ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    }

    public function test_settings_need_the_two_factor_code_when_it_is_on(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)->get('/settings')->assertRedirect('/two-factor/challenge');
    }
}
