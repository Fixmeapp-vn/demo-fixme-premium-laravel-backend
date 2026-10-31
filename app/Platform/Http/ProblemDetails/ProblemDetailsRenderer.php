<?php

declare(strict_types=1);

namespace App\Platform\Http\ProblemDetails;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Converts any exception reaching the HTTP layer into an RFC 9457 response.
 *
 * Registered in bootstrap/app.php via withExceptions()->render(). Exception
 * messages and stack traces are never sent to clients: HTTP exceptions get a
 * generic per-status detail, unknown exceptions a generic 500. Modules expose
 * client-facing errors by implementing {@see ProvidesProblemDetails}.
 */
final class ProblemDetailsRenderer
{
    public const CODE_INTERNAL = 'internal.error';

    public const CODE_VALIDATION = 'validation.failed';

    private const DETAILS = [
        400 => 'The request could not be understood.',
        401 => 'Authentication is required to access this resource.',
        403 => 'You are not allowed to perform this action.',
        404 => 'The requested resource was not found.',
        405 => 'The request method is not supported for this resource.',
        406 => 'The requested representation is not available.',
        408 => 'The request took too long to complete.',
        409 => 'The request conflicts with the current state of the resource.',
        413 => 'The request payload is too large.',
        415 => 'The request content type is not supported.',
        419 => 'The request has expired.',
        422 => 'The request could not be processed.',
        429 => 'Too many requests. Please retry later.',
        500 => 'An unexpected error occurred.',
        503 => 'The service is temporarily unavailable.',
    ];

    /**
     * @return Response|null null lets Laravel handle the exception itself
     *                       (exceptions that already carry a response).
     */
    public function render(Throwable $e): ?Response
    {
        if ($e instanceof HttpResponseException) {
            return null;
        }

        return $this->toProblemDetails($e)->toResponse();
    }

    public function toProblemDetails(Throwable $e): ProblemDetails
    {
        return match (true) {
            $e instanceof ProvidesProblemDetails => $e->toProblemDetails(),
            $e instanceof ValidationException => new ProblemDetails(
                status: $e->status,
                title: ProblemDetails::titleFor($e->status),
                code: self::CODE_VALIDATION,
                detail: 'The given data was invalid.',
                extensions: ['errors' => $e->errors()],
            ),
            $e instanceof AuthenticationException => ProblemDetails::forStatus(401, self::DETAILS[401]),
            $e instanceof HttpExceptionInterface => $this->fromHttpException($e),
            default => ProblemDetails::forStatus(500, self::DETAILS[500], self::CODE_INTERNAL),
        };
    }

    private function fromHttpException(HttpExceptionInterface $e): ProblemDetails
    {
        $status = $e->getStatusCode();
        $base = ProblemDetails::forStatus(
            $status,
            self::DETAILS[$status] ?? ($status >= 500 ? self::DETAILS[500] : null),
            $status >= 500 && ! isset(self::DETAILS[$status]) ? self::CODE_INTERNAL : null,
        );

        /** @var array<string, string|list<string>> $headers */
        $headers = $e->getHeaders();

        return new ProblemDetails(
            status: $base->status,
            title: $base->title,
            code: $base->code,
            detail: $base->detail,
            headers: $headers,
        );
    }
}
