<?php

namespace App\Providers;

use App\Models\Alias;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Observers\AliasObserver;
use App\Observers\DomainObserver;
use App\Observers\MailboxObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Domain::observe(DomainObserver::class);
        Mailbox::observe(MailboxObserver::class);
        Alias::observe(AliasObserver::class);
    }
}
