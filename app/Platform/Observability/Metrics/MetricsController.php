<?php

declare(strict_types=1);

namespace App\Platform\Observability\Metrics;

use Illuminate\Http\Response;

final class MetricsController
{
    public function __invoke(PrometheusRenderer $renderer): Response
    {
        return new Response($renderer->render(), 200, [
            'Content-Type' => PrometheusRenderer::CONTENT_TYPE,
            'Cache-Control' => 'no-store',
        ]);
    }
}
