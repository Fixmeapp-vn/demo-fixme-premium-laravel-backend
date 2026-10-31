<?php

use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Tests\Support\Artisan;

it('starts, handles SIGTERM gracefully and exits 0', function (int $signal, string $name) {
    $process = Artisan::process(['platform:worker', '--sleep=1']);
    $process->start();

    $deadline = microtime(true) + 20;

    while (! str_contains($process->getErrorOutput(), 'worker.started')) {
        if (! $process->isRunning() || microtime(true) > $deadline) {
            $this->fail("Worker did not start:\n".$process->getOutput().$process->getErrorOutput());
        }

        usleep(100_000);
    }

    $process->signal($signal);
    $exitCode = $process->wait();

    $logs = collect(explode("\n", trim($process->getErrorOutput())))
        ->map(fn (string $line) => json_decode($line, true))
        ->filter()
        ->keyBy('message');

    expect($exitCode)->toBe(0)
        ->and($logs)->toHaveKeys(['worker.started', 'worker.shutdown.started', 'worker.shutdown.completed'])
        ->and($logs['worker.shutdown.started']['context']['signal'])->toBe($name)
        ->and($logs['worker.started'])->toHaveKeys(['timestamp', 'level', 'service', 'environment']);
})->with([
    'SIGTERM' => [SIGTERM, 'SIGTERM'],
    'SIGINT' => [SIGINT, 'SIGINT'],
])->skip(! extension_loaded('pcntl'), 'ext-pcntl is required for graceful worker shutdown');

it('passes SHUTDOWN_TIMEOUT to queue:work as the job timeout', function () {
    config(['platform.shutdown_timeout' => 17]);

    $worker = Mockery::mock(Worker::class);
    $worker->shouldReceive('setName')->andReturnSelf();
    $worker->shouldReceive('setCache')->andReturnSelf();
    $worker->shouldReceive('daemon')
        ->once()
        ->withArgs(fn ($connection, $queue, WorkerOptions $options) => $options->timeout === 17)
        ->andReturn(0);

    $this->app->instance('queue.worker', $worker);

    $this->artisan('platform:worker')->assertExitCode(0);
});

it('stops cleanly when asked to stop on an empty queue', function () {
    $process = Artisan::process(['platform:worker', '--stop-when-empty']);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toContain('worker.shutdown.completed');
});
