<?php

declare(strict_types=1);

namespace App\Platform\Http;

/**
 * Applies the PHP execution time limit for the current request.
 *
 * Note: on Linux, max_execution_time counts CPU time only; time blocked on
 * I/O (database, HTTP calls, sleep) does not count. The wall-clock limit is
 * PHP-FPM's request_terminate_timeout, set from HTTP_REQUEST_TIMEOUT in
 * deployments/php-fpm/www.conf. This limit is the in-PHP backstop that still
 * produces a logged 500 Problem Details response for CPU-bound runaways.
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
