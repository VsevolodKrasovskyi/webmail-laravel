<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTwoFactor;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorController extends Controller
{
    private const RECOVERY_CODES = 8;

    /**
     * Optional. Not enabled: the secret to add to an authenticator app, then the recovery codes once.
     * Enabled: a status page where it can be turned off.
     */
    public function setup(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $recoveryCodes = $request->session()->get('recovery_codes');

        if ($user->hasTwoFactor()) {
            // A user whose code was not entered yet in this session cannot manage it.
            if (! $request->session()->get(EnsureTwoFactor::SESSION_KEY)) {
                return redirect()->route('two-factor.challenge');
            }

            return Inertia::render('Auth/TwoFactorSetup', ['enabled' => true, 'recoveryCodes' => $recoveryCodes]);
        }

        // The secret is kept (unconfirmed) so the QR code stays the same until the first code is accepted.
        if (blank($user->two_factor_secret)) {
            $user->forceFill(['two_factor_secret' => Totp::generateSecret()])->save();
        }

        return Inertia::render('Auth/TwoFactorSetup', [
            'secret' => $user->two_factor_secret,
            'otpauthUri' => Totp::uri($user->two_factor_secret, $user->email, config('app.name')),
            'recoveryCodes' => null,
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactor() || blank($user->two_factor_secret)) {
            return redirect()->route('two-factor.setup');
        }

        $data = $request->validate(['code' => ['required', 'string']]);

        $step = Totp::verify($user->two_factor_secret, $data['code']);

        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'The code is wrong or expired. Try the next one.']);
        }

        $codes = collect(range(1, self::RECOVERY_CODES))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => array_map($this->hashCode(...), $codes),
        ])->save();

        $request->session()->regenerate();
        $request->session()->put(EnsureTwoFactor::SESSION_KEY, true);

        return redirect()->route('two-factor.setup')->with('recovery_codes', $codes);
    }

    /** Turn it off. Needs a current code or a recovery code, so a stolen session cannot do it. */
    public function disable(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasTwoFactor() || ! $request->session()->get(EnsureTwoFactor::SESSION_KEY)) {
            return redirect()->route('two-factor.setup');
        }

        $this->checkSecondFactor($request, $user);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        return redirect()->route('two-factor.setup')->with('status', 'Two-factor authentication is off.');
    }

    public function challenge(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactor()) {
            return redirect('/dashboard');
        }

        if ($request->session()->get(EnsureTwoFactor::SESSION_KEY)) {
            return redirect('/dashboard');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasTwoFactor()) {
            return redirect('/dashboard');
        }

        $this->checkSecondFactor($request, $user);

        $request->session()->regenerate();
        $request->session()->put(EnsureTwoFactor::SESSION_KEY, true);

        return redirect()->intended('/dashboard');
    }

    /** A current app code or a recovery code, otherwise a validation error. */
    private function checkSecondFactor(Request $request, User $user): void
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'required_without:code'],
        ]);

        if (filled($data['code'] ?? null)) {
            $step = Totp::verify($user->two_factor_secret, $data['code'], $user->two_factor_last_step);

            if ($step === null) {
                throw ValidationException::withMessages(['code' => 'The code is wrong or expired.']);
            }

            $user->forceFill(['two_factor_last_step' => $step])->save();
        } elseif (! $this->useRecoveryCode($user, $data['recovery_code'])) {
            throw ValidationException::withMessages(['recovery_code' => 'This recovery code is not valid.']);
        }
    }

    /** A recovery code works once. */
    private function useRecoveryCode(User $user, string $input): bool
    {
        $hash = $this->hashCode(Str::lower(trim($input)));
        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
