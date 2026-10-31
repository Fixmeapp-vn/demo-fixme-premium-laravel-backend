<?php

use Illuminate\Queue\Events\WorkerInterrupted;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

beforeEach(fn () => Log::spy());

it('logs worker start', function () {
    Event::dispatch(new WorkerStarting('sync', 'default', new WorkerOptions));

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'worker.started'
            && $context['connection'] === 'sync'
            && $context['queue'] === 'default')
        ->once();
});

it('logs the start of a graceful shutdown with the signal name', function () {
    Event::dispatch(new WorkerInterrupted(SIGTERM, 'sync', 'default'));

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'worker.shutdown.started'
            && $context['signal'] === 'SIGTERM')
        ->once();
});

it('logs a clean shutdown as completed', function () {
    Event::dispatch(new WorkerStopping(0));

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === 'worker.shutdown.completed'
            && $context['status'] === 0)
        ->once();
});

it('logs a non-zero exit as a failed shutdown', function () {
    Event::dispatch(new WorkerStopping(1));

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context) => $message === 'worker.shutdown.failed'
            && $context['status'] === 1)
        ->once();
});
