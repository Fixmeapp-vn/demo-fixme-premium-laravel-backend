<?php

use App\Platform\Logging\PlatformJsonFormatter;
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

        'json' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => env('LOG_STREAM', 'php://stderr'),
            ],
            'formatter' => PlatformJsonFormatter::class,
            'formatter_with' => [
                'service' => env('SERVICE_NAME', 'fixme-premium-backend'),
                'environment' => env('APP_ENV', 'production'),
            ],
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
