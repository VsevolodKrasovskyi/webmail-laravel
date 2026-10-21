<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * The only way into the panel. A Google account is accepted when its
     * verified email is listed in ADMIN_EMAIL (see config/auth.php). The
     * admin user row is created on first sign-in.
     */
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

        if (! $verified || $email === '' || ! in_array($email, config('auth.admin_emails'), true)) {
            return redirect('/login')->with('error', 'This Google account is not allowed to sign in.');
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            // The password is never used, it only satisfies the column.
            ['name' => $googleUser->getName() ?: 'Admin', 'password' => Str::random(64)],
        );

        if (blank($user->google_id)) {
            $user->update(['google_id' => $googleUser->getId()]);
        }

        Auth::login($user);
        request()->session()->regenerate();

        return redirect()->intended('/dashboard');
    }
}
