<?php

declare(strict_types=1);

namespace App\Platform\Observability\Health;

use Illuminate\Http\JsonResponse;

/**
 * Liveness probe: the process is up and able to serve requests.
 *
 * Deliberately has no dependency on PostgreSQL, Redis or any other external
 * service. Database-aware readiness checks are added in BE-002.
 */
final class LivenessController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok'], 200, ['Cache-Control' => 'no-store']);
    }
}
