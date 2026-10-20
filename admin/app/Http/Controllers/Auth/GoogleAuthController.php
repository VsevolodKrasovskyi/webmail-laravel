<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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

    /**
     * Sign in with Google is only a second factor for an *existing* admin
     * account (matched by email) — it never creates new admin users, since
     * that would let anyone with a Google account self-register as admin.
     */
    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect('/login')->with('error', 'Google sign-in failed. Please try again.');
        }

        $user = User::where('email', $googleUser->getEmail())->first();

        if (! $user) {
            return redirect('/login')->with('error', 'No admin account matches that Google email.');
        }

        if (blank($user->google_id)) {
            $user->update(['google_id' => $googleUser->getId()]);
        }

        Auth::login($user);
        request()->session()->regenerate();

        return redirect('/dashboard');
    }
}
