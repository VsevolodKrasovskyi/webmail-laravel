<?php

use App\Http\Controllers\AliasController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\MailboxStatsController;
use App\Http\Controllers\WebmailController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/dashboard/mailbox-stats', MailboxStatsController::class)->name('dashboard.mailbox-stats');

    Route::resource('domains', DomainController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('/domains/{domain}/dns', [DomainController::class, 'syncDns'])->name('domains.dns');
    Route::resource('mailboxes', MailboxController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('/mailboxes/{mailbox}/webmail', [WebmailController::class, 'loginAsMailbox'])->name('mailboxes.webmail');
    Route::resource('aliases', AliasController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::prefix('webmail')->group(function () {
        Route::get('/', [WebmailController::class, 'index'])->name('webmail');
        Route::get('/compose', [WebmailController::class, 'compose'])->name('webmail.compose');
        Route::post('/login', [WebmailController::class, 'login'])->name('webmail.login');
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
