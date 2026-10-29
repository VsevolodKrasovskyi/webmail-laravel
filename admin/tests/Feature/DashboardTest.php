<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\WebmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function seedMailboxes(): void
    {
        $domain = Domain::create(['name' => 'example.com']);
        Mailbox::create(['domain_id' => $domain->id, 'email' => 'a@example.com', 'password' => 'x', 'quota_mb' => 100]);
        Mailbox::create(['domain_id' => $domain->id, 'email' => 'b@example.com', 'password' => 'x', 'quota_mb' => 100]);
        Mailbox::create(['domain_id' => $domain->id, 'email' => 'off@example.com', 'password' => 'x', 'quota_mb' => 100, 'is_active' => false]);
    }

    public function test_dashboard_renders_with_the_merged_analytics_data(): void
    {
        $this->seedMailboxes();
        config(['mail_server.dovecot_master_password' => null]);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('stats.mailboxes')
                ->has('growth', 12)
                ->has('domains.data', 1)
                ->where('domains.per_page', 5)
                ->has('top_domains', 1)
                ->has('scheduled.daily', 30)
                ->where('canReadMail', false));
    }

    public function test_the_analytics_page_is_gone(): void
    {
        $this->actingAs(User::factory()->create())->get('/analytics')->assertNotFound();
    }

    public function test_mailbox_stats_need_the_master_password(): void
    {
        config(['mail_server.dovecot_master_password' => null]);

        $this->actingAs(User::factory()->create())->getJson('/dashboard/mailbox-stats')->assertStatus(422);
    }

    public function test_mailbox_stats_are_paged_and_survive_a_broken_mailbox(): void
    {
        $this->seedMailboxes();
        config(['mail_server.dovecot_master_password' => 'secret', 'mail_server.dovecot_master_user' => 'admin']);

        $service = Mockery::mock(WebmailService::class);
        $service->shouldReceive('mailboxStats')
            ->with('a@example.com', 'secret', 'a@example.com*admin')
            ->andReturn(['inbox' => 5, 'unread' => 2, 'total' => 9]);
        $service->shouldReceive('mailboxStats')
            ->with('b@example.com', 'secret', 'b@example.com*admin')
            ->andThrow(new \RuntimeException('connection refused'));
        $this->app->instance(WebmailService::class, $service);

        $response = $this->actingAs(User::factory()->create())->getJson('/dashboard/mailbox-stats');

        $response->assertOk()
            ->assertJsonCount(2, 'mailboxes') // the inactive mailbox is not read
            ->assertJsonPath('mailboxes.0.unread', 2)
            ->assertJsonPath('mailboxes.1.error', 'Could not read this mailbox.')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.last_page', 1);
        $this->assertStringNotContainsString('connection refused', $response->getContent());
    }

    public function test_only_the_requested_page_of_mailboxes_is_read_over_imap(): void
    {
        $domain = Domain::create(['name' => 'example.com']);
        foreach (range(1, 12) as $i) {
            Mailbox::create(['domain_id' => $domain->id, 'email' => sprintf('m%02d@example.com', $i), 'password' => 'x', 'quota_mb' => 100]);
        }
        config(['mail_server.dovecot_master_password' => 'secret', 'mail_server.dovecot_master_user' => 'admin']);

        $service = Mockery::mock(WebmailService::class);
        $service->shouldReceive('mailboxStats')->times(5)->andReturn(['inbox' => 1, 'unread' => 1, 'total' => 1]);
        $this->app->instance(WebmailService::class, $service);

        $this->actingAs(User::factory()->create())->getJson('/dashboard/mailbox-stats?page=2&per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'mailboxes')
            ->assertJsonPath('mailboxes.0.email', 'm06@example.com')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.from', 6)
            ->assertJsonPath('meta.to', 10);
    }

    public function test_an_unknown_page_size_falls_back_to_five(): void
    {
        $domain = Domain::create(['name' => 'example.com']);
        foreach (range(1, 7) as $i) {
            Mailbox::create(['domain_id' => $domain->id, 'email' => "m{$i}@example.com", 'password' => 'x', 'quota_mb' => 100]);
        }
        config(['mail_server.dovecot_master_password' => 'secret']);
        $service = Mockery::mock(WebmailService::class);
        $service->shouldReceive('mailboxStats')->andReturn(['inbox' => 0, 'unread' => 0, 'total' => 0]);
        $this->app->instance(WebmailService::class, $service);

        $this->actingAs(User::factory()->create())->getJson('/dashboard/mailbox-stats?per_page=9999')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonCount(5, 'mailboxes');
    }

    public function test_totals_cover_every_active_mailbox(): void
    {
        $this->seedMailboxes();
        config(['mail_server.dovecot_master_password' => 'secret', 'mail_server.dovecot_master_user' => 'admin']);

        $service = Mockery::mock(WebmailService::class);
        $service->shouldReceive('mailboxStats')->andReturn(['inbox' => 3, 'unread' => 2, 'total' => 7]);
        $this->app->instance(WebmailService::class, $service);

        $this->actingAs(User::factory()->create())->getJson('/dashboard/mailbox-totals')
            ->assertOk()
            ->assertJsonPath('mailboxes', 2)
            ->assertJsonPath('unread_total', 4)
            ->assertJsonPath('inbox_total', 6)
            ->assertJsonPath('messages_total', 14);
    }

    public function test_the_domain_report_is_paged_in_the_database(): void
    {
        foreach (range(1, 12) as $i) {
            Domain::create(['name' => sprintf('d%02d.test', $i)]);
        }
        config(['mail_server.dovecot_master_password' => null]);

        $this->actingAs(User::factory()->create())->get('/dashboard?domains_page=2&domains_per_page=5')
            ->assertInertia(fn ($page) => $page
                ->has('domains.data', 5)
                ->where('domains.current_page', 2)
                ->where('domains.last_page', 3)
                ->where('domains.data.0.name', 'd06.test'));

        $this->actingAs(User::factory()->create())->get('/dashboard?domains_per_page=20')
            ->assertInertia(fn ($page) => $page->has('domains.data', 12)->where('domains.per_page', 20));
    }

    public function test_guests_cannot_read_stats(): void
    {
        $this->getJson('/dashboard/mailbox-stats')->assertUnauthorized();
    }
}
