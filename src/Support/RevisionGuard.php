<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Support;

use Closure;

/**
 * Suppresses the per-row revision bumps while an action writes many rows, so the
 * action can bump exactly once. Re-entrant; always restored in `finally`.
 */
final class RevisionGuard
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function suppress(Closure $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function suppressed(): bool
    {
        return self::$depth > 0;
    }
}
