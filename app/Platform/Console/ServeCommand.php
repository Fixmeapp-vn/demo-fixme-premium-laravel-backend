<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Config\PlatformConfigValidator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Local development server bound to HTTP_ADDR (or METRICS_ADDR with --metrics).
 *
 * A thin wrapper around `php artisan serve` so the listen address comes from
 * the validated configuration. Production runs PHP-FPM behind nginx instead
 * (see deployments/).
 */
#[AsCommand(name: 'platform:serve')]
final class ServeCommand extends Command
{
    protected $signature = 'platform:serve {--metrics : Serve the internal metrics listener (METRICS_ADDR) instead of the API}';

    protected $description = 'Serve the API (HTTP_ADDR) or the internal metrics listener (METRICS_ADDR) for local development';

    public function handle(Config $config): int
    {
        $address = $this->option('metrics')
            ? $config->get('platform.metrics.addr')
            : $config->get('platform.http.addr');

        $host = PlatformConfigValidator::hostOf($address);
        $port = PlatformConfigValidator::portOf($address);

        if ($host === null || $port === null) {
            $this->components->error('Invalid listen address.');

            return self::FAILURE;
        }

        return $this->call('serve', [
            '--host' => $host,
            '--port' => $port,
            '--no-reload' => true,
        ]);
    }
}
