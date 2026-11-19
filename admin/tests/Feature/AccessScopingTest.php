<?php

namespace Tests\Feature;

use App\Models\Alias;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\WebmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AccessScopingTest extends TestCase
{
    use RefreshDatabase;

    private Domain $a;

    private Domain $b;

    private Mailbox $a1;

    private Mailbox $a2;

    private Mailbox $b1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Domain::create(['name' => 'a.test']);
        $this->b = Domain::create(['name' => 'b.test']);
        $this->a1 = $this->mailbox($this->a, 'one');
        $this->a2 = $this->mailbox($this->a, 'two');
        $this->b1 = $this->mailbox($this->b, 'one');
        Alias::create(['source' => 'sales@a.test', 'destination' => 'one@a.test']);
        Alias::create(['source' => 'sales@b.test', 'destination' => 'one@b.test']);
    }

    private function mailbox(Domain $domain, string $local): Mailbox
    {
        return Mailbox::create(['domain_id' => $domain->id, 'email' => "{$local}@{$domain->name}", 'password' => 'x', 'quota_mb' => 100]);
    }

    private function domainManager(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->domains()->attach($this->a);

        return $user;
    }

    private function mailboxUser(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->mailboxes()->attach($this->a1);

        return $user;
    }

    public function test_an_admin_sees_everything(): void
    {
        $this->actingAsAdmin()->get('/domains')->assertInertia(fn ($p) => $p->has('domains.data', 2));
        $this->get('/mailboxes')->assertInertia(fn ($p) => $p->has('mailboxes.data', 3));
        $this->get('/aliases')->assertInertia(fn ($p) => $p->has('aliases.data', 2));
    }

    public function test_a_domain_manager_sees_only_their_domain(): void
    {
        $this->actingAsFullyAuthenticated($this->domainManager());

        $this->get('/domains')->assertInertia(fn ($p) => $p->has('domains.data', 1)->where('domains.data.0.name', 'a.test'));
        $this->get('/mailboxes')->assertInertia(fn ($p) => $p->has('mailboxes.data', 2)
            ->where('mailboxes.data.0.can_manage', true)
            ->where('createDomains', ['a.test']));
        $this->get('/aliases')->assertInertia(fn ($p) => $p->has('aliases.data', 1)->where('aliases.data.0.source', 'sales@a.test'));
        $this->get('/dashboard')->assertInertia(fn ($p) => $p->where('stats.domains.total', 1)->where('stats.mailboxes.total', 2)->where('stats.aliases.total', 1));
    }

    public function test_a_domain_manager_runs_their_domain_completely(): void
    {
        $this->actingAsFullyAuthenticated($this->domainManager());

        $this->post('/mailboxes', ['email' => 'new@a.test', 'password' => 'long-enough-1', 'quota_mb' => 50])->assertSessionHas('success');
        $this->put("/mailboxes/{$this->a1->id}", ['quota_mb' => 999, 'is_active' => false])->assertSessionHas('success');
        $this->assertSame(999, $this->a1->fresh()->quota_mb);
        $this->delete("/mailboxes/{$this->a2->id}")->assertSessionHas('success');

        $this->post('/aliases', ['source' => 'new@a.test', 'destination' => 'x@example.com'])->assertSessionHas('success');
        $alias = Alias::firstWhere('source', 'sales@a.test');
        $this->put("/aliases/{$alias->id}", ['destination' => 'y@example.com', 'is_active' => true])->assertSessionHas('success');
        $this->delete("/aliases/{$alias->id}")->assertSessionHas('success');

        $this->put("/domains/{$this->a->id}", ['is_active' => false])->assertSessionHas('success');
        $this->delete("/domains/{$this->a->id}")->assertSessionHas('success');
        $this->assertNull(Domain::find($this->a->id));
    }

    public function test_a_domain_manager_cannot_touch_another_domain(): void
    {
        $this->actingAsFullyAuthenticated($this->domainManager());
        $alias = Alias::firstWhere('source', 'sales@b.test');

        $this->post('/mailboxes', ['email' => 'new@b.test', 'password' => 'long-enough-1', 'quota_mb' => 50])->assertForbidden();
        $this->put("/mailboxes/{$this->b1->id}", ['quota_mb' => 1, 'is_active' => true])->assertForbidden();
        $this->delete("/mailboxes/{$this->b1->id}")->assertForbidden();
        $this->post('/aliases', ['source' => 'new@b.test', 'destination' => 'x@example.com'])->assertForbidden();
        $this->put("/aliases/{$alias->id}", ['destination' => 'y@example.com', 'is_active' => true])->assertForbidden();
        $this->delete("/aliases/{$alias->id}")->assertForbidden();
        $this->put("/domains/{$this->b->id}", ['is_active' => false])->assertForbidden();
        $this->delete("/domains/{$this->b->id}")->assertForbidden();
        $this->post("/domains/{$this->b->id}/dns")->assertForbidden();
        $this->post("/mailboxes/{$this->b1->id}/webmail")->assertForbidden();

        $this->assertNotNull($this->b1->fresh());
        $this->assertTrue($this->b->fresh()->is_active);
    }

    public function test_only_an_admin_adds_domains(): void
    {
        $this->actingAsFullyAuthenticated($this->domainManager())->post('/domains', ['name' => 'c.test'])->assertForbidden();

        $this->assertNull(Domain::firstWhere('name', 'c.test'));
    }

    public function test_a_mailbox_user_sees_only_that_mailbox_and_no_domain_pages_content(): void
    {
        $this->actingAsFullyAuthenticated($this->mailboxUser());

        $this->get('/mailboxes')->assertInertia(fn ($p) => $p->has('mailboxes.data', 1)
            ->where('mailboxes.data.0.email', 'one@a.test')
            ->where('mailboxes.data.0.can_manage', false)
            ->where('createDomains', []));
        $this->get('/domains')->assertInertia(fn ($p) => $p->has('domains.data', 0));
        $this->get('/aliases')->assertInertia(fn ($p) => $p->has('aliases.data', 0));
    }

    public function test_a_mailbox_user_changes_name_and_password_but_not_quota_or_status(): void
    {
        $this->actingAsFullyAuthenticated($this->mailboxUser());
        $before = $this->a1->fresh();

        $this->put("/mailboxes/{$this->a1->id}", ['name' => 'Ann', 'password' => 'a-new-password', 'quota_mb' => 99999, 'is_active' => false])
            ->assertSessionHas('success');

        $after = $this->a1->fresh();
        $this->assertSame('Ann', $after->name);
        $this->assertNotSame($before->password, $after->password);
        $this->assertSame($before->quota_mb, $after->quota_mb);
        $this->assertTrue($after->is_active);
    }

    public function test_a_mailbox_user_cannot_delete_create_or_reach_other_mailboxes(): void
    {
        $this->actingAsFullyAuthenticated($this->mailboxUser());

        $this->delete("/mailboxes/{$this->a1->id}")->assertForbidden();
        $this->put("/mailboxes/{$this->a2->id}", ['name' => 'x'])->assertForbidden();
        $this->post("/mailboxes/{$this->a2->id}/webmail")->assertForbidden();
        $this->post('/mailboxes', ['email' => 'new@a.test', 'password' => 'long-enough-1', 'quota_mb' => 50])->assertForbidden();
        $this->post('/aliases', ['source' => 'new@a.test', 'destination' => 'x@example.com'])->assertForbidden();
        $this->assertNotNull($this->a1->fresh());
    }

    public function test_a_mailbox_user_can_open_their_webmail(): void
    {
        config(['mail_server.dovecot_master_password' => 'secret', 'mail_server.dovecot_master_user' => 'admin']);
        $service = Mockery::mock(WebmailService::class);
        $service->shouldReceive('testLogin')->once();
        $this->app->instance(WebmailService::class, $service);

        $this->actingAsFullyAuthenticated($this->mailboxUser())->post("/mailboxes/{$this->a1->id}/webmail")->assertRedirect('/webmail');
    }

    public function test_dashboard_numbers_are_limited_for_a_mailbox_user(): void
    {
        config(['mail_server.dovecot_master_password' => 'secret', 'mail_server.dovecot_master_user' => 'admin']);
        $service = Mockery::mock(WebmailService::class);
        $service->shouldReceive('mailboxStats')->once()->with('one@a.test', 'secret', 'one@a.test*admin')
            ->andReturn(['inbox' => 1, 'unread' => 1, 'total' => 1]);
        $this->app->instance(WebmailService::class, $service);

        $this->actingAsFullyAuthenticated($this->mailboxUser());

        $this->get('/dashboard')->assertInertia(fn ($p) => $p->where('stats.mailboxes.total', 1)->where('stats.domains.total', 0));
        $this->getJson('/dashboard/mailbox-stats')->assertJsonPath('meta.total', 1);
    }

    public function test_a_user_who_is_not_an_admin_has_no_user_management(): void
    {
        $this->actingAsFullyAuthenticated($this->domainManager());

        $this->get('/users')->assertForbidden();
        $this->post('/invitations', ['email' => 'x@example.com', 'domain_ids' => [$this->a->id]])->assertForbidden();
    }

    public function test_the_nav_flags_follow_the_role(): void
    {
        $this->actingAsFullyAuthenticated($this->mailboxUser())->get('/mailboxes')
            ->assertInertia(fn ($p) => $p->where('auth.user.is_admin', false)->where('auth.user.manages_domains', false));
        $this->actingAsFullyAuthenticated($this->domainManager())->get('/mailboxes')
            ->assertInertia(fn ($p) => $p->where('auth.user.is_admin', false)->where('auth.user.manages_domains', true));
    }

    public function test_a_demoted_admin_loses_the_admin_rights(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $this->assertTrue($admin->isAdmin());

        $admin->forceFill(['is_admin' => false])->save();

        $this->assertFalse($admin->fresh()->isAdmin());
        $this->actingAsFullyAuthenticated($admin->fresh())->get('/domains')->assertInertia(fn ($p) => $p->has('domains.data', 0));
    }
}
