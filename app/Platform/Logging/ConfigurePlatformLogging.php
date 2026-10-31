<?php

declare(strict_types=1);

namespace App\Platform\Logging;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Log\Logger;
use Monolog\Handler\AbstractHandler;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Level;
use Monolog\Logger as Monolog;

/**
 * Logging channel "tap" that applies the validated platform settings
 * (platform.log_level, platform.service_name, platform.environment), so
 * LOG_LEVEL / SERVICE_NAME / APP_ENV are read in exactly one place
 * (config/platform.php) and Monolog always uses the validated values.
 */
final class ConfigurePlatformLogging
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof Monolog) {
            return;
        }

        $level = $this->level();
        $formatter = new PlatformJsonFormatter($this->string('platform.service_name', 'app'), $this->string('platform.environment', 'production'));

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof AbstractHandler) {
                $handler->setLevel($level);
            }

            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter($formatter);
            }
        }
    }

    private function level(): Level
    {
        $name = strtolower($this->string('platform.log_level', 'info'));

        foreach (Level::cases() as $level) {
            if (strtolower($level->getName()) === $name) {
                return $level;
            }
        }

        // Unreachable once PlatformConfigValidator has run; keep logging usable while it reports.
        return Level::Info;
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
