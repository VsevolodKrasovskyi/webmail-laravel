<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Services\MailboxStatsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Message totals over every active mailbox (the unread notification and the Messages card).
 * Needs all mailboxes, so it is separate from the paged table.
 */
class MailboxTotalsController extends Controller
{
    public function __invoke(Request $request, MailboxStatsReader $reader): JsonResponse
    {
        if (! $reader->configured()) {
            return response()->json(['error' => 'Set DOVECOT_MASTER_PASSWORD to read message counts.'], 422);
        }

        $rows = $reader->rows(Mailbox::where('is_active', true)->orderBy('email')->get(), $request->boolean('refresh'));

        return response()->json([
            'mailboxes' => $rows->count(),
            'unread_total' => (int) $rows->sum('unread'),
            'inbox_total' => (int) $rows->sum('inbox'),
            'messages_total' => (int) $rows->sum('total'),
        ]);
    }
}
