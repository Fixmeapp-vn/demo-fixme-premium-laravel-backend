<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Background worker entry point: `php artisan queue:work` with the per-job
 * timeout taken from QUEUE_JOB_TIMEOUT.
 *
 * On SIGINT/SIGTERM Laravel's worker (pcntl) stops taking new jobs, lets the
 * in-flight job finish and exits 0. A job running longer than QUEUE_JOB_TIMEOUT
 * is killed and the worker exits non-zero, so shutdown can never hang. The
 * process manager's grace period (SHUTDOWN_TIMEOUT, e.g. supervisor
 * `stopwaitsecs`) is validated to be longer than the job timeout.
 */
#[AsCommand(name: 'platform:worker')]
final class WorkerCommand extends Command
{
    protected $signature = 'platform:worker
        {connection? : Queue connection (defaults to QUEUE_CONNECTION)}
        {--queue= : Comma-separated queues to listen on}
        {--sleep=3 : Seconds to sleep when no job is available}
        {--tries=1 : Attempts before a job is marked failed}
        {--max-jobs=0 : Stop after processing this many jobs}
        {--max-time=0 : Stop after running for this many seconds}
        {--stop-when-empty : Stop when the queue is empty}';

    protected $description = 'Run the queue worker with the platform job timeout and lifecycle logging';

    public function handle(Config $config): int
    {
        if (! extension_loaded('pcntl')) {
            $this->components->warn('ext-pcntl is not loaded: SIGTERM/SIGINT will not trigger a graceful shutdown.');
        }

        $timeout = $config->get('platform.queue.job_timeout', 25);

        $arguments = array_filter([
            'connection' => $this->argument('connection'),
            '--queue' => $this->option('queue'),
            '--sleep' => $this->option('sleep'),
            '--tries' => $this->option('tries'),
            '--max-jobs' => $this->option('max-jobs'),
            '--max-time' => $this->option('max-time'),
            '--stop-when-empty' => $this->option('stop-when-empty'),
            '--timeout' => is_numeric($timeout) ? (int) $timeout : 25,
        ], static fn (mixed $value): bool => $value !== null && $value !== false);

        return $this->call('queue:work', $arguments);
    }
}
