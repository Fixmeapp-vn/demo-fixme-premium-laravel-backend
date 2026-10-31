<?php

declare(strict_types=1);

namespace App\Platform\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Masks values whose keys look sensitive (passwords, tokens, keys, cookies…)
 * anywhere in a record's context or extra data.
 */
final class RedactSensitiveData implements ProcessorInterface
{
    public const MASK = '[REDACTED]';

    private const PATTERN = '/(pass(word|wd)?|secret|token|authori[sz]ation|cookie|api[_-]?key|app[_-]?key|private[_-]?key|credential|session)/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data, int $depth = 0): array
    {
        if ($depth > 10) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::PATTERN, $key) === 1) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value, $depth + 1);
            }
        }

        return $data;
    }
}
