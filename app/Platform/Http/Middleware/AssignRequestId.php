<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Platform\Http\RequestId;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository as Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the request ID before anything else runs.
 *
 * - Accepts a valid incoming X-Request-ID, otherwise generates one.
 * - Stores it as a request attribute and in Laravel's Context, so it is
 *   attached to every log record (including exception reports) and is
 *   propagated to queued jobs dispatched during the request.
 * - Echoes the effective ID in the X-Request-ID response header.
 */
final class AssignRequestId
{
    public function __construct(
        private readonly Context $context,
        private readonly Config $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $this->header();
        $incoming = $request->headers->get($header);

        $id = RequestId::isValid($incoming) ? (string) $incoming : RequestId::generate();

        $request->attributes->set(RequestId::ATTRIBUTE, $id);
        $request->headers->set($header, $id);
        $this->context->add(RequestId::CONTEXT_KEY, $id);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set($header, $id);

        return $response;
    }

    private function header(): string
    {
        $header = $this->config->get('platform.request_id.header', 'X-Request-ID');

        return is_string($header) ? $header : 'X-Request-ID';
    }
}
