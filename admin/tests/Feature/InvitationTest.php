<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Invitation;
use App\Models\Mailbox;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function domain(string $name = 'a.test'): Domain
    {
        return Domain::create(['name' => $name]);
    }

    private function mailbox(Domain $domain, string $local = 'info'): Mailbox
    {
        return Mailbox::create(['domain_id' => $domain->id, 'email' => "{$local}@{$domain->name}", 'password' => 'x', 'quota_mb' => 100]);
    }

    public function test_an_admin_invites_a_user_to_a_domain(): void
    {
        Notification::fake();
        $domain = $this->domain();

        $this->actingAsAdmin()
            ->post('/invitations', ['email' => 'New@Example.com', 'domain_ids' => [$domain->id]])
            ->assertRedirect()->assertSessionHas('success');

        $invitation = Invitation::firstWhere('email', 'new@example.com');
        $this->assertSame([$domain->id], $invitation->access['domains']);
        Notification::assertSentOnDemand(InvitationNotification::class);
    }

    public function test_an_admin_invites_a_user_to_a_single_mailbox(): void
    {
        Notification::fake();
        $mailbox = $this->mailbox($this->domain());

        $this->actingAsAdmin()->post('/invitations', ['email' => 'new@example.com', 'mailbox_ids' => [$mailbox->id]])->assertSessionHas('success');

        $this->assertSame([$mailbox->id], Invitation::firstWhere('email', 'new@example.com')->access['mailboxes']);
    }

    public function test_an_invitation_needs_a_domain_or_a_mailbox(): void
    {
        $this->actingAsAdmin()->post('/invitations', ['email' => 'new@example.com'])->assertSessionHasErrors(['domain_ids', 'mailbox_ids']);

        $this->assertSame(0, Invitation::count());
    }

    public function test_a_user_who_is_not_an_admin_cannot_invite_or_list_users(): void
    {
        $domain = $this->domain();
        $user = User::factory()->withTwoFactor()->create();
        $user->domains()->attach($domain);

        $this->actingAsFullyAuthenticated($user)->post('/invitations', ['email' => 'x@example.com', 'domain_ids' => [$domain->id]])->assertForbidden();
        $this->get('/users')->assertForbidden();
        $this->put("/users/{$user->id}", ['domain_ids' => []])->assertForbidden();
        $this->assertSame(0, Invitation::count());
    }

    public function test_an_existing_user_cannot_be_invited(): void
    {
        $domain = $this->domain();
        User::factory()->create(['email' => 'here@example.com']);
        $this->actingAsAdmin();

        $this->post('/invitations', ['email' => 'here@example.com', 'domain_ids' => [$domain->id]])->assertSessionHasErrors('email');
    }

    public function test_a_failed_send_keeps_the_invitation_and_says_so(): void
    {
        $domain = $this->domain();
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('smtp down'));

        $this->actingAsAdmin()->post('/invitations', ['email' => 'new@example.com', 'domain_ids' => [$domain->id]])
            ->assertSessionHas('error');

        $this->assertSame(1, Invitation::count());
    }

    public function test_accepting_creates_a_verified_user_with_the_access_and_signs_them_in(): void
    {
        $domain = $this->domain();
        $mailbox = $this->mailbox($this->domain('b.test'));
        [, $token] = Invitation::issue('new@example.com', ['domains' => [$domain->id], 'mailboxes' => [$mailbox->id]], null);

        $this->get("/invitations/{$token}")->assertOk()->assertInertia(fn ($p) => $p->component('Auth/AcceptInvitation')->where('email', 'new@example.com'));

        $this->post("/invitations/{$token}", ['name' => 'Newbie', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])
            ->assertRedirect('/dashboard');

        $user = User::firstWhere('email', 'new@example.com');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('a-long-password', $user->password));
        $this->assertSame([$domain->id], $user->domains()->pluck('domains.id')->all());
        $this->assertSame([$mailbox->id], $user->mailboxes()->pluck('mailboxes.id')->all());
        $this->assertFalse($user->isAdmin());
        $this->get('/dashboard')->assertOk();
    }

    public function test_an_invitation_works_once(): void
    {
        $domain = $this->domain();
        [, $token] = Invitation::issue('new@example.com', ['domains' => [$domain->id]], null);
        $body = ['name' => 'Newbie', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'];

        $this->post("/invitations/{$token}", $body);
        auth()->logout();

        $this->get("/invitations/{$token}")->assertRedirect('/login');
        $this->post("/invitations/{$token}", $body)->assertRedirect('/login');
        $this->assertSame(1, User::where('email', 'new@example.com')->count());
    }

    public function test_an_expired_or_unknown_token_is_refused(): void
    {
        $domain = $this->domain();
        [$invitation, $token] = Invitation::issue('new@example.com', ['domains' => [$domain->id]], null);
        $invitation->update(['expires_at' => now()->subMinute()]);

        $this->get("/invitations/{$token}")->assertRedirect('/login');
        $this->get('/invitations/'.str_repeat('a', 64))->assertRedirect('/login');
    }

    public function test_resending_replaces_the_link(): void
    {
        Notification::fake();
        $domain = $this->domain();
        [$invitation, $old] = Invitation::issue('new@example.com', ['domains' => [$domain->id]], null);

        $this->actingAsAdmin()->post("/invitations/{$invitation->id}/resend")->assertSessionHas('success');

        $this->assertNull(Invitation::findPending($old));
    }

    public function test_an_invited_address_can_join_with_google_instead(): void
    {
        User::factory()->admin()->create();
        $domain = $this->domain();
        Invitation::issue('new@example.com', ['domains' => [$domain->id]], null);
        $google = (new SocialiteUser)->map(['id' => 'g-9', 'name' => 'Newbie', 'email' => 'new@example.com', 'avatar' => null]);
        $google->user = ['email_verified' => true];
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($google);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        $user = User::firstWhere('email', 'new@example.com');
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->isAdmin());
        $this->assertSame([$domain->id], $user->domains()->pluck('domains.id')->all());
        $this->assertSame(0, Invitation::pending()->count());
    }

    public function test_the_access_of_a_user_can_be_changed(): void
    {
        $a = $this->domain('a.test');
        $b = $this->domain('b.test');
        $user = User::factory()->create();
        $user->domains()->attach($a);

        $this->actingAsAdmin()->put("/users/{$user->id}", ['domain_ids' => [$b->id], 'mailbox_ids' => []])->assertSessionHas('success');

        $this->assertSame([$b->id], $user->domains()->pluck('domains.id')->all());
    }

    public function test_an_admin_cannot_delete_themselves(): void
    {
        $this->actingAsAdmin();
        $me = auth()->user();

        $this->delete("/users/{$me->id}")->assertSessionHas('error');

        $this->assertNotNull(User::find($me->id));
    }
}
