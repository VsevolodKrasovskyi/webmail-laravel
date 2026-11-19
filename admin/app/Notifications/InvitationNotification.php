<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification
{
    public function __construct(private readonly Invitation $invitation, private readonly string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name');

        return (new MailMessage)
            ->subject("You are invited to {$app}")
            ->line("You have been invited to the {$app} mail panel.")
            ->action('Accept the invitation', route('invitations.show', $this->token))
            ->line('The link works until '.$this->invitation->expires_at->format('d.m.Y H:i').' ('.config('app.timezone').').')
            ->line('You will set a password. If you did not expect this, ignore the e-mail.');
    }
}
