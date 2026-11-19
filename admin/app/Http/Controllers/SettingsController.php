<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The signed-in user's own account: name, password and two-factor authentication. */
class SettingsController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings', [
            'profile' => ['name' => $user->name, 'email' => $user->email],
            'hasPassword' => filled($user->password),
            'twoFactor' => $user->hasTwoFactor(),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $request->user()->update($data);

        return back()->with('success', 'Name saved.');
    }

    /** A user who signed in with Google only has no password yet and sets the first one without a current one. */
    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => [filled($user->password) ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        if (filled($user->password) && ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is wrong.']);
        }

        $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();

        // Other devices are signed out, this session stays.
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        return back()->with('success', 'Password changed.');
    }
}
