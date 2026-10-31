<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Platform\Http\ExecutionTimeLimit;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caps PHP execution time per request at HTTP_REQUEST_TIMEOUT seconds.
 *
 * This is CPU time on Linux (see ExecutionTimeLimit); the wall-clock limit is
 * PHP-FPM's request_terminate_timeout (deployments/php-fpm/www.conf).
 *
 * When the limit is exceeded PHP raises a fatal "Maximum execution time"
 * error, which Laravel's HandleExceptions converts into a FatalError that is
 * reported and rendered as a 500 Problem Details response. The web server's
 * upstream timeout (nginx fastcgi_read_timeout) should be slightly higher so
 * PHP, not the proxy, terminates the request.
 */
final class EnforceRequestTimeout
{
    public function __construct(
        private readonly ExecutionTimeLimit $limit,
        private readonly Config $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $seconds = $this->config->get('platform.http.request_timeout', 30);

        $this->limit->apply(is_numeric($seconds) ? (int) $seconds : 30);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
