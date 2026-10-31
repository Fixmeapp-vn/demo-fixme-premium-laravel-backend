<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Monotonic request timing shared by the access-log and metrics middleware.
 */
final class RequestTiming
{
    /** Route label used for requests that matched no route (404/405). */
    public const UNMATCHED_ROUTE = 'unmatched';

    private const ATTRIBUTE = 'platform.started_at_ns';

    public static function start(Request $request): void
    {
        if (! $request->attributes->has(self::ATTRIBUTE)) {
            $request->attributes->set(self::ATTRIBUTE, self::nowNs());
        }
    }

    /**
     * Seconds elapsed since {@see start()} was called for this request.
     */
    public static function elapsedSeconds(Request $request): float
    {
        $startedAt = $request->attributes->get(self::ATTRIBUTE);

        if (! is_int($startedAt)) {
            return 0.0;
        }

        return max(0, self::nowNs() - $startedAt) / 1_000_000_000;
    }

    /**
     * Bounded route label: the route URI template (never the raw path).
     */
    public static function routeLabel(Request $request): string
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return self::UNMATCHED_ROUTE;
        }

        return '/'.ltrim($route->uri(), '/');
    }

    private static function nowNs(): int
    {
        $now = hrtime(true);

        return is_int($now) ? $now : (int) (microtime(true) * 1_000_000_000);
    }
}
