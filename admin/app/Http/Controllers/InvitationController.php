<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Users join by invitation only, and only administrators invite (routes use the `admin` middleware). */
class InvitationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'domain_ids' => ['array', 'required_without:mailbox_ids'],
            'domain_ids.*' => ['integer', 'exists:domains,id'],
            'mailbox_ids' => ['array', 'required_without:domain_ids'],
            'mailbox_ids.*' => ['integer', 'exists:mailboxes,id'],
        ], [
            'domain_ids.required_without' => 'Pick a domain or a mailbox.',
            'mailbox_ids.required_without' => 'Pick a domain or a mailbox.',
        ]);

        if (in_array($data['email'], config('auth.admin_emails'), true)) {
            throw ValidationException::withMessages(['email' => 'This address is an administrator (ADMIN_EMAIL), it needs no invitation.']);
        }

        if (User::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages(['email' => 'This user already exists. Change their access in the list.']);
        }

        // A new invitation replaces the pending one for the same address.
        Invitation::pending()->where('email', $data['email'])->delete();

        [$invitation, $token] = Invitation::issue($data['email'], [
            'domains' => $data['domain_ids'] ?? [],
            'mailboxes' => $data['mailbox_ids'] ?? [],
        ], $request->user());

        return $this->send($invitation, $token, 'Invitation sent.');
    }

    /** A new link, the old one stops working. */
    public function resend(Invitation $invitation): RedirectResponse
    {
        abort_if($invitation->accepted_at !== null, 404);

        return $this->send($invitation, $invitation->renew(), 'Invitation sent again.');
    }

    public function destroy(Invitation $invitation): RedirectResponse
    {
        $invitation->delete();

        return back()->with('success', 'Invitation cancelled.');
    }

    private function send(Invitation $invitation, string $token, string $message): RedirectResponse
    {
        try {
            Notification::route('mail', $invitation->email)->notify(new InvitationNotification($invitation, $token));
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', "The invitation for {$invitation->email} was saved but the e-mail could not be sent. Check the mail settings and use Resend.");
        }

        return back()->with('success', $message);
    }
}
