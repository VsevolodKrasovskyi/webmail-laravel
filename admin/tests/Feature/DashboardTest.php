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
                ->has('domains', 1)
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

    public function test_mailbox_stats_count_per_mailbox_and_survive_a_broken_one(): void
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

        $response = $this->actingAs(User::factory()->create())->getJson('/dashboard/mailbox-stats?refresh=1');

        $response->assertOk()
            ->assertJsonCount(2, 'mailboxes') // the inactive mailbox is not read
            ->assertJsonPath('mailboxes.0.unread', 2)
            ->assertJsonPath('mailboxes.1.error', 'Could not read this mailbox.')
            ->assertJsonPath('unread_total', 2)
            ->assertJsonPath('messages_total', 9);
        $this->assertStringNotContainsString('connection refused', $response->getContent());
    }

    public function test_guests_cannot_read_stats(): void
    {
        $this->getJson('/dashboard/mailbox-stats')->assertUnauthorized();
    }
}
