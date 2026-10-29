<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListPageSizeTest extends TestCase
{
    use RefreshDatabase;

    private function seedDomains(int $count): Domain
    {
        $first = null;
        foreach (range(1, $count) as $i) {
            $domain = Domain::create(['name' => sprintf('d%03d.test', $i)]);
            $first ??= $domain;
        }

        return $first;
    }

    public function test_domains_default_to_ten_per_page(): void
    {
        $this->seedDomains(25);

        $this->actingAs(User::factory()->create())->get('/domains')
            ->assertInertia(fn ($page) => $page->has('domains.data', 10)->where('domains.per_page', 10)->where('filters.per_page', 10));
    }

    public function test_domains_accept_only_the_offered_sizes(): void
    {
        $this->seedDomains(120);
        $user = User::factory()->create();

        foreach ([10, 20, 50, 100] as $size) {
            $this->actingAs($user)->get("/domains?per_page={$size}")
                ->assertInertia(fn ($page) => $page->has('domains.data', $size)->where('domains.per_page', $size));
        }

        // Anything else falls back to 10.
        foreach ([15, 7, 1000, 'abc', 0] as $bad) {
            $this->actingAs($user)->get("/domains?per_page={$bad}")
                ->assertInertia(fn ($page) => $page->where('domains.per_page', 10));
        }
    }

    public function test_mailboxes_page_size_and_page_links_keep_the_choice(): void
    {
        $domain = Domain::create(['name' => 'example.com']);
        foreach (range(1, 60) as $i) {
            Mailbox::create(['domain_id' => $domain->id, 'email' => sprintf('m%03d@example.com', $i), 'password' => 'x', 'quota_mb' => 100]);
        }

        $this->actingAs(User::factory()->create())->get('/mailboxes?per_page=20&page=2')
            ->assertInertia(fn ($page) => $page
                ->has('mailboxes.data', 20)
                ->where('mailboxes.current_page', 2)
                ->where('mailboxes.last_page', 3)
                ->where('mailboxes.data.0.email', 'm021@example.com')
                ->where('filters.per_page', 20));

        $links = $this->actingAs(User::factory()->create())->get('/mailboxes?per_page=20')->viewData('page')['props']['mailboxes']['next_page_url'];
        $this->assertStringContainsString('per_page=20', $links);
    }
}
