<?php

/*
|--------------------------------------------------------------------------
| Platform Routes
|--------------------------------------------------------------------------
|
| Operational endpoints outside the versioned API. No session, CSRF or
| authentication middleware applies here.
|
*/

use App\Platform\Http\Middleware\RestrictToMetricsListener;
use App\Platform\Observability\Health\LivenessController;
use App\Platform\Observability\Metrics\MetricsController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', LivenessController::class)->name('platform.health.live');

// Served only on the internal METRICS_ADDR listener; 404 everywhere else.
Route::get('/metrics', MetricsController::class)
    ->middleware(RestrictToMetricsListener::class)
    ->name('platform.metrics');
