<?php

declare(strict_types=1);

namespace App\Platform\Http;

/**
 * Applies the PHP execution time limit for the current request.
 *
 * Wrapped in a class so the limit can be asserted in tests without putting
 * a real time limit on the test process.
 */
class ExecutionTimeLimit
{
    public function apply(int $seconds): void
    {
        set_time_limit($seconds);
    }
}
