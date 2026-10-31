<?php

declare(strict_types=1);

namespace App\Platform\Http\ProblemDetails;

/**
 * Implemented by (module) exceptions that map to a specific client-facing
 * problem, e.g. a domain exception with code "user.email_taken" and status 409.
 *
 * Everything returned here is sent to clients, so it must not contain
 * internal details.
 */
interface ProvidesProblemDetails
{
    public function toProblemDetails(): ProblemDetails;
}
