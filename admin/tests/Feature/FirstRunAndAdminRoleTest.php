<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FirstRunAndAdminRoleTest extends TestCase
{
    use RefreshDatabase;

    private array $form = ['name' => 'Boss', 'email' => 'Boss@Example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'];

    public function test_with_no_users_the_login_page_leads_to_registration(): void
    {
        $this->get('/login')->assertRedirect('/register');
        $this->get('/register')->assertOk()->assertInertia(fn ($p) => $p->component('Auth/Register'));
    }

    public function test_the_first_registered_user_becomes_a_verified_administrator(): void
    {
        $this->post('/register', $this->form)->assertRedirect('/dashboard');

        $user = User::firstWhere('email', 'boss@example.com');
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->isAdmin());
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('a-long-password', $user->password));
        $this->get('/users')->assertOk();
    }

    public function test_registration_closes_after_the_first_user(): void
    {
        $this->post('/register', $this->form);
        auth()->logout();

        $this->get('/register')->assertRedirect('/login');
        $this->post('/register', ['email' => 'second@example.com'] + $this->form)->assertRedirect('/login');

        $this->assertSame(1, User::count());
        $this->get('/login')->assertOk();
    }

    public function test_registration_validates_the_password(): void
    {
        $this->post('/register', ['password' => 'short', 'password_confirmation' => 'short'] + $this->form)->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_an_administrator_promotes_a_user_after_typing_the_address(): void
    {
        $target = User::factory()->create(['email' => 'staff@example.com']);
        $this->actingAsAdmin();

        $this->put("/users/{$target->id}/admin", ['is_admin' => true, 'confirm_email' => 'wrong@example.com'])->assertSessionHasErrors('confirm_email');
        $this->assertFalse($target->fresh()->isAdmin());

        $this->put("/users/{$target->id}/admin", ['is_admin' => true, 'confirm_email' => ' Staff@Example.com '])->assertSessionHas('success');
        $this->assertTrue($target->fresh()->isAdmin());

        $this->put("/users/{$target->id}/admin", ['is_admin' => false, 'confirm_email' => 'staff@example.com'])->assertSessionHas('success');
        $this->assertFalse($target->fresh()->isAdmin());
    }

    public function test_an_unverified_user_cannot_be_promoted(): void
    {
        $target = User::factory()->unverified()->create(['email' => 'staff@example.com']);

        $this->actingAsAdmin()->put("/users/{$target->id}/admin", ['is_admin' => true, 'confirm_email' => 'staff@example.com'])->assertSessionHas('error');

        $this->assertFalse($target->fresh()->isAdmin());
    }

    public function test_an_administrator_cannot_change_their_own_role(): void
    {
        $this->actingAsAdmin();
        $me = auth()->user();

        $this->put("/users/{$me->id}/admin", ['is_admin' => false, 'confirm_email' => $me->email])->assertSessionHas('error');

        $this->assertTrue($me->fresh()->isAdmin());
    }

    public function test_a_plain_user_cannot_promote_anyone(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $other = User::factory()->create();

        $this->actingAsFullyAuthenticated($user)->put("/users/{$other->id}/admin", ['is_admin' => true, 'confirm_email' => $other->email])->assertForbidden();
        $this->put("/users/{$user->id}/admin", ['is_admin' => true, 'confirm_email' => $user->email])->assertForbidden();

        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_a_promoted_user_gets_the_admin_pages(): void
    {
        $target = User::factory()->create();
        $this->actingAsAdmin()->put("/users/{$target->id}/admin", ['is_admin' => true, 'confirm_email' => $target->email]);

        $this->actingAsFullyAuthenticated($target->fresh())->get('/users')->assertOk();
    }
}
