<?php

declare(strict_types=1);

namespace App\Platform\Queue;

use BackedEnum;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\WorkerInterrupted;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Psr\Log\LoggerInterface;

/**
 * Structured lifecycle logs for `php artisan queue:work`.
 *
 * Signal handling itself is Laravel's (pcntl): on SIGINT/SIGTERM/SIGQUIT the
 * worker finishes the in-flight job, then exits with status 0. This
 * subscriber only reports the lifecycle:
 *
 *   worker.started            WorkerStarting
 *   worker.shutdown.started   WorkerInterrupted (signal received)
 *   worker.shutdown.completed WorkerStopping with status 0
 *   worker.shutdown.failed    WorkerStopping with non-zero status (e.g. job timeout)
 *   worker.job.timed_out      JobTimedOut (shutdown deadline exceeded)
 */
final class WorkerLifecycleLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function onStarting(WorkerStarting $event): void
    {
        $this->logger->info('worker.started', [
            'connection' => $event->connectionName,
            'queue' => $event->queue,
            'pid' => getmypid(),
        ]);
    }

    public function onInterrupted(WorkerInterrupted $event): void
    {
        $this->logger->info('worker.shutdown.started', [
            'signal' => self::signalName($event->signal),
            'connection' => $event->connectionName,
            'queue' => $event->queue,
        ]);
    }

    public function onStopping(WorkerStopping $event): void
    {
        $status = (int) $event->status;

        $context = [
            'status' => $status,
            'reason' => $event->reason instanceof BackedEnum ? $event->reason->value : $event->reason,
            'jobs_processed' => $event->jobsProcessed,
            'connection' => $event->connectionName,
            'queue' => $event->queue,
        ];

        if ($status === 0) {
            $this->logger->info('worker.shutdown.completed', $context);
        } else {
            $this->logger->error('worker.shutdown.failed', $context);
        }
    }

    public function onJobTimedOut(JobTimedOut $event): void
    {
        $this->logger->error('worker.job.timed_out', [
            'connection' => $event->connectionName,
            'job' => $event->job->resolveName(),
            'timeout' => $event->timeout,
        ]);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            WorkerStarting::class => 'onStarting',
            WorkerInterrupted::class => 'onInterrupted',
            WorkerStopping::class => 'onStopping',
            JobTimedOut::class => 'onJobTimedOut',
        ];
    }

    private static function signalName(int $signal): string
    {
        return match ($signal) {
            defined('SIGTERM') ? SIGTERM : 15 => 'SIGTERM',
            defined('SIGINT') ? SIGINT : 2 => 'SIGINT',
            defined('SIGQUIT') ? SIGQUIT : 3 => 'SIGQUIT',
            default => (string) $signal,
        };
    }
}
