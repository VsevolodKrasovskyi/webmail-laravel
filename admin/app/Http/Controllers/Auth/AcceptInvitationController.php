<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AcceptInvitationController extends Controller
{
    public function show(string $token): Response|RedirectResponse
    {
        $invitation = Invitation::findPending($token);

        if (! $invitation) {
            return redirect()->route('login')->with('error', 'This invitation is invalid or has expired. Ask an administrator for a new one.');
        }

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            'email' => $invitation->email,
            'googleConfigured' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = Invitation::findPending($token);

        if (! $invitation || User::where('email', $invitation->email)->exists()) {
            return redirect()->route('login')->with('error', 'This invitation is invalid or has expired. Ask an administrator for a new one.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        $user = $invitation->accept($data['name'], $data['password']);

        Auth::login($user);
        $request->session()->regenerate();

        // Two-factor setup follows from the middleware.
        return redirect('/dashboard');
    }
}
