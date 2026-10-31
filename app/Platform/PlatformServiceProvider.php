<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Config\PlatformConfigValidator;
use App\Platform\Console\ServeCommand;
use App\Platform\Console\WorkerCommand;
use App\Platform\Http\ExecutionTimeLimit;
use App\Platform\Observability\Metrics\CacheMetricsStore;
use App\Platform\Observability\Metrics\HttpMetrics;
use App\Platform\Observability\Metrics\MetricsStore;
use App\Platform\Queue\WorkerLifecycleLogger;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the shared technical platform (app/Platform).
 *
 * Has no knowledge of business modules: modules register their own service
 * providers in bootstrap/providers.php.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    /**
     * Setup/maintenance commands that must work before a valid .env exists
     * (e.g. `composer install` runs package:discover; `make setup` runs
     * key:generate). Every other entry point validates configuration.
     */
    public const UNVALIDATED_COMMANDS = ['package:discover', 'key:generate', 'config:clear', 'cache:clear', 'optimize:clear'];

    public function register(): void
    {
        $this->app->singleton(ExecutionTimeLimit::class);

        $this->app->singleton(MetricsStore::class, static function (Application $app): MetricsStore {
            /** @var Config $config */
            $config = $app->make(Config::class);
            $store = $config->get('platform.metrics.store');
            $instance = $config->get('platform.metrics.instance');

            return new CacheMetricsStore(
                $app->make(CacheFactory::class)->store(is_string($store) ? $store : null),
                is_string($instance) && $instance !== '' ? $instance : (gethostname() ?: 'local'),
            );
        });

        $this->app->singleton(HttpMetrics::class, static function (Application $app): HttpMetrics {
            /** @var Config $config */
            $config = $app->make(Config::class);

            /** @var list<float|int> $buckets */
            $buckets = (array) $config->get('platform.metrics.duration_buckets', []);

            return new HttpMetrics($app->make(MetricsStore::class), $buckets);
        });
    }

    public function boot(Config $config, PlatformConfigValidator $validator, Dispatcher $events): void
    {
        // Fail fast on invalid configuration: HTTP boot, artisan and queue:work all pass through here.
        if (! $this->app->runningConsoleCommand(self::UNVALIDATED_COMMANDS)) {
            $validator->validate($config);
        }

        $events->subscribe(WorkerLifecycleLogger::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ServeCommand::class, WorkerCommand::class]);
        }
    }
}
