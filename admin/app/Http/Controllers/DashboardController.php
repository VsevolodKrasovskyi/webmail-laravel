<?php

namespace App\Http\Controllers;

use App\Models\Alias;
use App\Models\Domain;
use App\Models\Mailbox;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => [
                'domains' => [
                    'total' => Domain::count(),
                    'active' => Domain::where('is_active', true)->count(),
                ],
                'mailboxes' => [
                    'total' => Mailbox::count(),
                    'active' => Mailbox::where('is_active', true)->count(),
                ],
                'aliases' => [
                    'total' => Alias::count(),
                    'active' => Alias::where('is_active', true)->count(),
                ],
            ],
        ]);
    }
}
