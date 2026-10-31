<?php

use App\Platform\Logging\ConfigurePlatformLogging;
use App\Platform\Logging\PlatformJsonFormatter;
use App\Platform\Logging\RedactSensitiveData;
use Illuminate\Log\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;

function logRecord(array $context = [], array $extra = [], Level $level = Level::Info): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable('2026-10-31T10:00:00.123456+00:00'),
        channel: 'test',
        level: $level,
        message: 'something happened',
        context: $context,
        extra: $extra,
    );
}

it('formats records as one JSON object per line with the shared fields', function () {
    $formatter = new PlatformJsonFormatter('fixme-premium-backend', 'staging');

    $line = $formatter->format(logRecord(['user_count' => 3], ['request_id' => 'abc-123'], Level::Warning));

    expect($line)->toEndWith("\n")
        ->and(substr_count($line, "\n"))->toBe(1);

    expect(json_decode($line, true, flags: JSON_THROW_ON_ERROR))->toBe([
        'timestamp' => '2026-10-31T10:00:00.123456+00:00',
        'level' => 'warning',
        'service' => 'fixme-premium-backend',
        'environment' => 'staging',
        'message' => 'something happened',
        'channel' => 'test',
        'request_id' => 'abc-123',
        'context' => ['user_count' => 3],
    ]);
});

it('does not let extra data override the reserved fields', function () {
    $line = (new PlatformJsonFormatter('svc', 'production'))
        ->format(logRecord(extra: ['service' => 'spoofed', 'level' => 'debug']));

    expect(json_decode($line, true))
        ->service->toBe('svc')
        ->level->toBe('info');
});

it('includes exception details for logs but stays valid JSON', function () {
    $line = (new PlatformJsonFormatter('svc', 'production'))
        ->format(logRecord(['exception' => new RuntimeException('boom')], level: Level::Error));

    $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['context']['exception'])
        ->class->toBe(RuntimeException::class)
        ->message->toBe('boom')
        ->toHaveKey('trace');
});

it('redacts sensitive keys anywhere in context and extra', function () {
    $record = (new RedactSensitiveData)(logRecord(
        context: [
            'password' => 'hunter2',
            'nested' => ['api_key' => 'k', 'Authorization' => 'Bearer t', 'keep' => 'me'],
            'APP_KEY' => 'base64:xyz',
        ],
        extra: ['session_id' => 's', 'request_id' => 'r'],
    ));

    expect($record->context)->toBe([
        'password' => RedactSensitiveData::MASK,
        'nested' => ['api_key' => RedactSensitiveData::MASK, 'Authorization' => RedactSensitiveData::MASK, 'keep' => 'me'],
        'APP_KEY' => RedactSensitiveData::MASK,
    ])->and($record->extra)->toBe([
        'session_id' => RedactSensitiveData::MASK,
        'request_id' => 'r',
    ]);
});

it('applies the validated platform settings to the json channel', function () {
    config([
        'platform.log_level' => 'warning',
        'platform.service_name' => 'svc-from-platform',
        'platform.environment' => 'staging',
    ]);

    $logger = new Logger(new Monolog('json', [new StreamHandler('php://memory')]));

    app(ConfigurePlatformLogging::class)($logger);

    $handler = $logger->getLogger()->getHandlers()[0];
    $line = $handler->getFormatter()->format(logRecord());

    expect($handler->getLevel())->toBe(Level::Warning)
        ->and($handler->getFormatter())->toBeInstanceOf(PlatformJsonFormatter::class)
        ->and(json_decode($line, true))
        ->service->toBe('svc-from-platform')
        ->environment->toBe('staging');
});

it('reads log settings from config/platform.php only', function () {
    expect(config('logging.channels.json'))
        ->tap->toBe([ConfigurePlatformLogging::class])
        ->processors->toBe([RedactSensitiveData::class])
        ->not->toHaveKeys(['level', 'formatter_with']);
});
