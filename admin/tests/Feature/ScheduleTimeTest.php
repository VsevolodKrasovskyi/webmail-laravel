<?php

namespace Tests\Feature;

use App\Models\ScheduledEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_scheduled_time_is_stored_in_the_app_time_zone(): void
    {
        config([
            'app.timezone' => 'Europe/Sofia',
            'mail_server.dovecot_master_password' => 'secret',
        ]);
        date_default_timezone_set('Europe/Sofia');

        $session = ['webmail_credentials' => encrypt(['email' => 'a@example.com', 'password' => 'x'])];
        // 2030-06-01 15:00 in Sofia (UTC+3 in summer) is 12:00 UTC, which is what a browser sends.
        $this->actingAs(User::factory()->create())->withSession($session)->postJson('/webmail/schedule', [
            'to' => 'b@example.com', 'subject' => 'Hi', 'body' => '<p>x</p>', 'send_at' => '2030-06-01T12:00:00.000Z',
        ])->assertOk();

        $mail = ScheduledEmail::first();

        $this->assertSame('2030-06-01 15:00:00', $mail->getRawOriginal('send_at'));
        $this->assertSame('2030-06-01T12:00:00+00:00', $mail->send_at->utc()->toIso8601String());
    }
}
