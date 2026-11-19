<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailTransportTest extends TestCase
{
    public function test_the_postfix_mailer_uses_implicit_tls_and_the_master_user_of_the_sender_mailbox(): void
    {
        config([
            'mail.mailers.postfix.username' => 'noreply@a.test*admin',
            'mail.mailers.postfix.password' => 'secret',
            'mail.mailers.postfix.host' => '127.0.0.1',
        ]);

        $transport = Mail::mailer('postfix')->getSymfonyTransport();

        $this->assertSame('smtps://127.0.0.1', (string) $transport);
        // Connected over 127.0.0.1, so the certificate of the public host name cannot be matched.
        $this->assertFalse($transport->getStream()->getStreamOptions()['ssl']['verify_peer']);
        $this->assertSame('noreply@a.test*admin', $transport->getUsername());
        $this->assertSame('secret', $transport->getPassword());
    }
}
