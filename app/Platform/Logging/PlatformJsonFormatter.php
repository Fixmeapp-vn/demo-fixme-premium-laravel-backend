<?php

declare(strict_types=1);

namespace App\Platform\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * One JSON object per line with a stable top-level shape:
 *
 *   {"timestamp":"…","level":"info","service":"…","environment":"…","message":"…",
 *    "channel":"…","request_id":"…","context":{…}}
 *
 * Values from Laravel's Context (e.g. request_id) arrive in the record's "extra"
 * and are lifted to the top level. Sensitive keys are redacted by
 * {@see RedactSensitiveData} before the record reaches this formatter.
 */
final class PlatformJsonFormatter extends JsonFormatter
{
    private const RESERVED = ['timestamp', 'level', 'service', 'environment', 'message', 'channel', 'context'];

    public function __construct(
        private readonly string $service = 'app',
        private readonly string $environment = 'production',
        bool $includeStacktraces = true,
    ) {
        parent::__construct(self::BATCH_MODE_NEWLINES, true, true, $includeStacktraces);
    }

    public function format(LogRecord $record): string
    {
        $data = [
            'timestamp' => $record->datetime->format('Y-m-d\TH:i:s.uP'),
            'level' => strtolower($record->level->getName()),
            'service' => $this->service,
            'environment' => $this->environment,
            'message' => $record->message,
            'channel' => $record->channel,
        ];

        foreach ($record->extra as $key => $value) {
            if (! in_array($key, self::RESERVED, true)) {
                $data[$key] = $value;
            }
        }

        if ($record->context !== []) {
            $data['context'] = $record->context;
        }

        return $this->toJson($this->normalize($data), true)."\n";
    }
}
