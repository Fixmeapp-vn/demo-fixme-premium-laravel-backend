<?php

namespace Tests\Support;

use App\Platform\Http\ProblemDetails\ProblemDetails;
use App\Platform\Http\ProblemDetails\ProvidesProblemDetails;
use RuntimeException;

/**
 * Example of a module-specific error exposing its own problem code.
 */
final class ConflictException extends RuntimeException implements ProvidesProblemDetails
{
    public function toProblemDetails(): ProblemDetails
    {
        return new ProblemDetails(
            status: 409,
            title: 'Conflict',
            code: 'example.already_exists',
            detail: 'The example already exists.',
        );
    }
}
