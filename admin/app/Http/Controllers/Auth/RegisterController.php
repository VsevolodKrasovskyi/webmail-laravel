<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** First run only: with no users yet, whoever registers first becomes the administrator. */
class RegisterController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        if (User::exists()) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/Register', [
            'googleConfigured' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (User::exists()) {
            return redirect()->route('login');
        }

        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        $user = User::create($data);
        // There is no mail to verify with yet (the sender mailbox does not exist on a fresh install).
        $user->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/dashboard');
    }
}
