<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MailboxCreateTest extends TestCase
{
    use RefreshDatabase;

    private function create(string $email): TestResponse
    {
        return $this->actingAsAdmin()->post('/mailboxes', [
            'email' => $email, 'password' => 'long-enough-1', 'quota_mb' => 100,
        ]);
    }

    public function test_a_mailbox_can_be_created_on_an_existing_domain(): void
    {
        Domain::create(['name' => 'example.com']);

        $this->create('Info@Example.com')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('mailboxes', ['email' => 'info@example.com']);
    }

    public function test_an_unknown_domain_is_rejected(): void
    {
        Domain::create(['name' => 'example.com']);

        $this->create('info@invented.test')->assertSessionHasErrors('email');

        $this->assertSame(0, Mailbox::count());
    }

    public function test_a_disabled_domain_is_rejected(): void
    {
        Domain::create(['name' => 'off.test', 'is_active' => false]);

        $this->create('info@off.test')->assertSessionHasErrors('email');
    }

    public function test_odd_local_parts_are_rejected(): void
    {
        Domain::create(['name' => 'example.com']);

        foreach (['"a b"@example.com', 'a..b@example.com', '.a@example.com', 'a b@example.com', 'a,b@example.com'] as $bad) {
            $this->create($bad)->assertSessionHasErrors('email');
        }

        $this->assertSame(0, Mailbox::count());
    }

    public function test_the_dropdown_lists_only_active_domains(): void
    {
        Domain::create(['name' => 'b.test']);
        Domain::create(['name' => 'a.test']);
        Domain::create(['name' => 'off.test', 'is_active' => false]);

        $this->actingAsAdmin()->get('/mailboxes')
            ->assertInertia(fn ($page) => $page->where('domains', ['a.test', 'b.test']));
    }
}
