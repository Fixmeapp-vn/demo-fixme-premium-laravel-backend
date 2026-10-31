<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Platform\Config\PlatformConfigValidator;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Only serves the wrapped route on the internal metrics listener.
 *
 * The decision uses SERVER_PORT, i.e. the port the server actually accepted
 * the connection on (set by `php -S` / nginx `$server_port`), not the
 * client-controlled Host header. Anywhere else the route behaves as if it did
 * not exist (404 Problem Details), and when metrics are disabled as well.
 *
 * Any proxy or server in front of PHP must forward the real listener port as
 * SERVER_PORT (our nginx template does: `fastcgi_param SERVER_PORT $server_port`).
 * If it does not, /metrics answers 404 everywhere: this fails closed.
 */
final class RestrictToMetricsListener
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $enabled = (bool) $this->config->get('platform.metrics.enabled', true);
        $port = PlatformConfigValidator::portOf($this->config->get('platform.metrics.addr'));

        if (! $enabled || $port === null || (int) $request->server('SERVER_PORT') !== $port) {
            throw new NotFoundHttpException;
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
