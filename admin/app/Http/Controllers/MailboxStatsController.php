<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Support\PageSize;
use App\Services\MailboxStatsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One page (always 5) of the dashboard's "Messages by mailbox" table, paged in the database
 * (`page`). Only the mailboxes on that page are read over IMAP.
 */
class MailboxStatsController extends Controller
{
    public function __invoke(Request $request, MailboxStatsReader $reader): JsonResponse
    {
        if (! $reader->configured()) {
            return response()->json(['error' => 'Set DOVECOT_MASTER_PASSWORD to read message counts.'], 422);
        }

        $page = Mailbox::where('is_active', true)
            ->orderBy('email')
            ->paginate(PageSize::DASHBOARD);

        return response()->json([
            'mailboxes' => $reader->rows($page->getCollection(), $request->boolean('refresh')),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem() ?? 0,
                'to' => $page->lastItem() ?? 0,
            ],
        ]);
    }
}
