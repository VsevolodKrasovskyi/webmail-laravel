<?php

use App\Http\Controllers\AliasController;
use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\MailboxStatsController;
use App\Http\Controllers\MailboxTotalsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebmailController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:20,1');
    // Only while there are no users: the first one becomes the administrator.
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');

    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');

    // Users join by invitation only.
    Route::get('/invitations/{token}', [AcceptInvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [AcceptInvitationController::class, 'store'])->middleware('throttle:10,1')->name('invitations.accept');
});

// Signed in, but not necessarily verified or past two-factor authentication yet.
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware('signed')->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:6,1')->name('verification.send');
});

// Two-factor authentication is optional. Once on, a code is asked each session.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/two-factor/setup', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('/two-factor/setup', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1')->name('two-factor.confirm');
    Route::post('/two-factor/disable', [TwoFactorController::class, 'disable'])->middleware('throttle:6,1')->name('two-factor.disable');
    Route::get('/two-factor/challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor/challenge', [TwoFactorController::class, 'verify'])->middleware('throttle:6,1')->name('two-factor.verify');
});

Route::middleware(['auth', 'verified', 'two-factor'])->group(function () {

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/dashboard/mailbox-stats', MailboxStatsController::class)->name('dashboard.mailbox-stats');
    Route::get('/dashboard/mailbox-totals', MailboxTotalsController::class)->name('dashboard.mailbox-totals');

    Route::resource('domains', DomainController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('/domains/{domain}/dns', [DomainController::class, 'syncDns'])->name('domains.dns');
    Route::resource('mailboxes', MailboxController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('/mailboxes/{mailbox}/webmail', [WebmailController::class, 'loginAsMailbox'])->name('mailboxes.webmail');
    Route::resource('aliases', AliasController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::get('/settings', [SettingsController::class, 'show'])->name('settings');
    Route::put('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile');
    Route::put('/settings/password', [SettingsController::class, 'updatePassword'])->middleware('throttle:6,1')->name('settings.password');

    Route::middleware('admin')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::put('/users/{user}/admin', [UserController::class, 'setAdmin'])->name('users.admin');
        Route::post('/users/{user}/two-factor-reset', [UserController::class, 'resetTwoFactor'])->name('users.two-factor-reset');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::post('/invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('invitations.resend');
        Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
    });

    Route::prefix('webmail')->group(function () {
        Route::get('/', [WebmailController::class, 'index'])->name('webmail');
        Route::get('/compose', [WebmailController::class, 'compose'])->name('webmail.compose');
        Route::post('/logout', [WebmailController::class, 'logout'])->name('webmail.logout');
        Route::get('/folders', [WebmailController::class, 'folders']);
        Route::post('/folders', [WebmailController::class, 'storeFolder']);
        Route::post('/folders/delete', [WebmailController::class, 'destroyFolder']);
        Route::post('/folders/empty', [WebmailController::class, 'emptyFolder']);
        Route::get('/messages', [WebmailController::class, 'messages']);
        Route::get('/messages/{uid}', [WebmailController::class, 'show']);
        Route::get('/messages/{uid}/attachments/{attachmentId}', [WebmailController::class, 'downloadAttachment']);
        Route::post('/messages/{uid}/delete', [WebmailController::class, 'destroyMessage']);
        Route::post('/messages/{uid}/unread', [WebmailController::class, 'markUnread']);
        Route::post('/messages/{uid}/move', [WebmailController::class, 'moveMessage']);
        Route::post('/send', [WebmailController::class, 'send']);
        Route::post('/drafts', [WebmailController::class, 'saveDraft']);
        Route::post('/schedule', [WebmailController::class, 'schedule']);
    });
});
