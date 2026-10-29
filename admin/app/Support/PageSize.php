<?php

namespace App\Support;

/**
 * Rows per page. The Domains and Mailboxes lists let the user choose (`per_page`),
 * the dashboard tables always show a fixed number.
 */
class PageSize
{
    public const LIST_SIZES = [10, 20, 50, 100];

    public const DASHBOARD = 5;

    /** The requested list size if it is one of the offered sizes, otherwise the smallest (10). */
    public static function list(mixed $requested): int
    {
        return in_array((int) $requested, self::LIST_SIZES, true) ? (int) $requested : self::LIST_SIZES[0];
    }
}
