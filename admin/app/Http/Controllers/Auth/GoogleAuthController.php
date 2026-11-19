<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect('/login')->with('error', 'Google sign-in failed. Please try again.');
        }

        $verified = filter_var(
            $googleUser->user['email_verified'] ?? $googleUser->user['verified_email'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        $email = strtolower((string) $googleUser->getEmail());

        if (! $verified || $email === '') {
            return redirect('/login')->with('error', 'This Google account is not allowed to sign in.');
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            // Not in the panel yet: the very first user becomes the administrator, later ones need an invitation.
            if (! User::exists()) {
                $user = User::create(['email' => $email, 'name' => $googleUser->getName() ?: 'Admin']);
                $user->forceFill(['is_admin' => true])->save();
            } elseif ($invitation = Invitation::pendingForEmail($email)) {
                $user = $invitation->accept($googleUser->getName() ?: $email);
            } else {
                return redirect('/login')->with('error', 'This Google account is not allowed to sign in.');
            }
        }

        // Google confirmed the address, and keeps the Google identity and profile picture current.
        $user->forceFill([
            'google_id' => $user->google_id ?: $googleUser->getId(),
            'avatar' => $googleUser->getAvatar(),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Auth::login($user);
        request()->session()->regenerate();

        // Two-factor authentication is asked for after this, as for a password sign-in.
        return redirect()->intended('/dashboard');
    }
}
