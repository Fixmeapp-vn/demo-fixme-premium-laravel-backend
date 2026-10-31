<?php

declare(strict_types=1);

namespace App\Platform\Config;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Validator;

/**
 * Validates the platform configuration at boot.
 *
 * All violations are collected and reported together. Messages reference the
 * environment variable names (not their values), so secrets are never echoed.
 */
final class PlatformConfigValidator
{
    public const ENVIRONMENTS = ['development', 'test', 'staging', 'production'];

    public const LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    private const ADDRESS_PATTERN = '/^(?:\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?):(\d{1,5})$/';

    public function __construct(
        private readonly ValidationFactory $validation,
    ) {}

    /**
     * @throws InvalidConfigurationException
     */
    public function validate(Config $config): void
    {
        $errors = $this->errors($config);

        if ($errors !== []) {
            throw InvalidConfigurationException::withErrors($errors);
        }
    }

    /**
     * @return list<string>
     */
    public function errors(Config $config): array
    {
        $data = [
            'APP_ENV' => $config->get('platform.environment'),
            'APP_KEY' => $config->get('app.key'),
            'SERVICE_NAME' => $config->get('platform.service_name'),
            'LOG_LEVEL' => $config->get('platform.log_level'),
            'HTTP_ADDR' => $config->get('platform.http.addr'),
            'HTTP_REQUEST_TIMEOUT' => $config->get('platform.http.request_timeout'),
            'HTTP_READ_HEADER_TIMEOUT' => $config->get('platform.http.read_header_timeout'),
            'HTTP_IDLE_TIMEOUT' => $config->get('platform.http.idle_timeout'),
            'SHUTDOWN_TIMEOUT' => $config->get('platform.shutdown_timeout'),
            'METRICS_ENABLED' => $config->get('platform.metrics.enabled'),
            'METRICS_ADDR' => $config->get('platform.metrics.addr'),
            'METRICS_STORE' => $config->get('platform.metrics.store'),
        ];

        /** @var array<string, mixed> $stores */
        $stores = (array) $config->get('cache.stores', []);

        $rules = [
            'APP_ENV' => ['required', 'string', 'in:'.implode(',', self::ENVIRONMENTS)],
            'APP_KEY' => ['required_if:APP_ENV,staging,production', 'nullable', 'string'],
            'SERVICE_NAME' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'LOG_LEVEL' => ['required', 'string', 'in:'.implode(',', self::LOG_LEVELS)],
            'HTTP_ADDR' => ['required', 'string', 'regex:'.self::ADDRESS_PATTERN],
            'HTTP_REQUEST_TIMEOUT' => ['required', 'integer', 'between:1,300'],
            'HTTP_READ_HEADER_TIMEOUT' => ['required', 'integer', 'between:1,120'],
            'HTTP_IDLE_TIMEOUT' => ['required', 'integer', 'between:1,900'],
            'SHUTDOWN_TIMEOUT' => ['required', 'integer', 'between:1,300'],
            'METRICS_ENABLED' => ['boolean'],
            'METRICS_ADDR' => ['required', 'string', 'regex:'.self::ADDRESS_PATTERN],
            'METRICS_STORE' => ['required', 'string', 'in:'.implode(',', array_keys($stores))],
        ];

        $messages = [
            'APP_ENV.in' => 'APP_ENV must be one of: '.implode(', ', self::ENVIRONMENTS).'.',
            'APP_KEY.required_if' => 'APP_KEY is required in staging and production (generate one with `php artisan key:generate --show`).',
            'LOG_LEVEL.in' => 'LOG_LEVEL must be one of: '.implode(', ', self::LOG_LEVELS).'.',
            'SERVICE_NAME.regex' => 'SERVICE_NAME may only contain lowercase letters, digits, ".", "_" and "-".',
            'HTTP_ADDR.regex' => 'HTTP_ADDR must be a "host:port" address, e.g. 127.0.0.1:8000.',
            'METRICS_ADDR.regex' => 'METRICS_ADDR must be a "host:port" address, e.g. 127.0.0.1:9100.',
            'METRICS_STORE.in' => 'METRICS_STORE must name a cache store configured in config/cache.php ('.implode(', ', array_keys($stores)).').',
            'between' => ':attribute must be an integer number of seconds between :min and :max.',
            'integer' => ':attribute must be an integer number of seconds.',
            'required' => ':attribute is required.',
            'boolean' => ':attribute must be true or false.',
        ];

        /** @var Validator $validator */
        // Display attributes as the environment variable names they come from.
        $attributes = array_combine(array_keys($data), array_keys($data));

        $validator = $this->validation->make($data, $rules, $messages, $attributes);

        $validator->after(function (Validator $validator) use ($data): void {
            foreach (['HTTP_ADDR', 'METRICS_ADDR'] as $key) {
                $port = self::portOf($data[$key]);

                if ($port !== null && ($port < 1 || $port > 65535)) {
                    $validator->errors()->add($key, "{$key} port must be between 1 and 65535.");
                }
            }

            $httpPort = self::portOf($data['HTTP_ADDR']);

            if ($httpPort !== null && $httpPort === self::portOf($data['METRICS_ADDR'])) {
                $validator->errors()->add('METRICS_ADDR', 'METRICS_ADDR must use a different port than HTTP_ADDR so metrics stay off the public listener.');
            }
        });

        return array_values($validator->errors()->all());
    }

    /**
     * Extract the port of a "host:port" address, or null when the address is malformed.
     */
    public static function portOf(mixed $address): ?int
    {
        if (! is_string($address) || preg_match(self::ADDRESS_PATTERN, $address, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Extract the host of a "host:port" address, or null when the address is malformed.
     */
    public static function hostOf(mixed $address): ?string
    {
        if (self::portOf($address) === null) {
            return null;
        }

        /** @var string $address */
        return trim(substr($address, 0, (int) strrpos($address, ':')), '[]');
    }
}
