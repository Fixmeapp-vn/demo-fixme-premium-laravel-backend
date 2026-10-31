<?php

use App\Platform\Http\Middleware\AssignRequestId;
use App\Platform\Http\Middleware\EnforceRequestTimeout;
use App\Platform\Http\Middleware\LogAccess;
use App\Platform\Http\Middleware\RecordHttpMetrics;
use Illuminate\Contracts\Http\Kernel;

it('runs the request id middleware first, then metrics and access logging', function () {
    $global = app(Kernel::class)->getGlobalMiddleware();

    expect(array_slice($global, 0, 3))->toBe([
        AssignRequestId::class,
        RecordHttpMetrics::class,
        LogAccess::class,
    ])->and(end($global))->toBe(EnforceRequestTimeout::class);
});
