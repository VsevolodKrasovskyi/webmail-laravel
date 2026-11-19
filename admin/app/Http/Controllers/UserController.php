<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Invitation;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Users and their access. Administrators only (routes use the `admin` middleware). */
class UserController extends Controller
{
    public function index(): Response
    {
        $domains = Domain::orderBy('name')->get(['id', 'name']);
        $mailboxes = Mailbox::orderBy('email')->get(['id', 'email']);

        return Inertia::render('Users/Index', [
            'users' => User::with(['domains:id', 'mailboxes:id'])->orderBy('email')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_admin' => $u->isAdmin(),
                'two_factor' => $u->hasTwoFactor(),
                'verified' => $u->hasVerifiedEmail(),
                'has_password' => filled($u->password),
                'domain_ids' => $u->domains->pluck('id')->all(),
                'mailbox_ids' => $u->mailboxes->pluck('id')->all(),
            ]),
            'invitations' => Invitation::pending()->latest('id')->get()->map(fn (Invitation $i) => [
                'id' => $i->id,
                'email' => $i->email,
                'expires_at' => $i->expires_at->toIso8601String(),
                'domain_ids' => $i->access['domains'] ?? [],
                'mailbox_ids' => $i->access['mailboxes'] ?? [],
            ]),
            'domains' => $domains,
            'mailboxes' => $mailboxes,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'domain_ids' => ['array'],
            'domain_ids.*' => ['integer', 'exists:domains,id'],
            'mailbox_ids' => ['array'],
            'mailbox_ids.*' => ['integer', 'exists:mailboxes,id'],
        ]);

        $user->domains()->sync($data['domain_ids'] ?? []);
        $user->mailboxes()->sync($data['mailbox_ids'] ?? []);

        return back()->with('success', 'Access updated.');
    }

    /** Lost device and recovery codes: the user sets two-factor authentication up again at the next sign-in. */
    public function resetTwoFactor(User $user): RedirectResponse
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        return back()->with('success', "Two-factor authentication reset for {$user->email}. They set it up again at the next sign-in.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $user->delete();

        return back()->with('success', 'User deleted.');
    }
}
