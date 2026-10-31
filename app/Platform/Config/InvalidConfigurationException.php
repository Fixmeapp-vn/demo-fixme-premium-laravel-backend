<?php

declare(strict_types=1);

namespace App\Platform\Config;

use RuntimeException;

/**
 * Thrown at boot when the platform configuration is invalid.
 *
 * The message lists every violation so operators can fix them in one pass.
 */
final class InvalidConfigurationException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    private function __construct(string $message, public readonly array $errors)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $errors
     */
    public static function withErrors(array $errors): self
    {
        $lines = array_map(static fn (string $error): string => '  - '.$error, $errors);

        return new self(
            "Invalid application configuration. Fix the following and restart:\n".implode("\n", $lines),
            $errors,
        );
    }
}
