<?php

use App\Platform\Logging\ConfigurePlatformLogging;
use App\Platform\Logging\RedactSensitiveData;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | All application, access and worker logs go to the "json" channel: one
    | JSON object per line on stderr (or LOG_STREAM), suitable for container
    | log collectors. See App\Platform\Logging\PlatformJsonFormatter.
    |
    */

    'default' => env('LOG_CHANNEL', 'json'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        // Level, formatter (PlatformJsonFormatter), service name and environment are applied by
        // ConfigurePlatformLogging from the validated config/platform.php values, so
        // LOG_LEVEL / SERVICE_NAME / APP_ENV are read in one place only.
        'json' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => env('LOG_STREAM', 'php://stderr'),
            ],
            'tap' => [ConfigurePlatformLogging::class],
            'processors' => [RedactSensitiveData::class],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => env('LOG_EMERGENCY_PATH', 'php://stderr'),
        ],

    ],

];
