<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Request ID rules shared by the middleware and anything that needs the
 * current request's ID.
 *
 * Accepted incoming IDs: 1–128 characters from [A-Za-z0-9._:-].
 * Anything else is replaced by a freshly generated UUIDv7.
 */
final class RequestId
{
    public const ATTRIBUTE = 'request_id';

    public const CONTEXT_KEY = 'request_id';

    public const MAX_LENGTH = 128;

    private const PATTERN = '/^[A-Za-z0-9._:\-]{1,128}$/';

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    public static function generate(): string
    {
        return (string) Str::uuid7();
    }

    public static function from(Request $request): ?string
    {
        $id = $request->attributes->get(self::ATTRIBUTE);

        return is_string($id) ? $id : null;
    }
}
