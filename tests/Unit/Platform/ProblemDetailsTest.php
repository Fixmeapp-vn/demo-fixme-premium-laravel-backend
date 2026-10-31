<?php

use App\Platform\Http\ProblemDetails\ProblemDetails;
use App\Platform\Http\ProblemDetails\ProblemDetailsRenderer;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\Support\ConflictException;

it('derives stable codes and titles from the status', function (int $status, string $title, string $code) {
    expect(ProblemDetails::titleFor($status))->toBe($title)
        ->and(ProblemDetails::codeFor($status))->toBe($code);
})->with([
    [404, 'Not Found', 'http.not_found'],
    [405, 'Method Not Allowed', 'http.method_not_allowed'],
    [429, 'Too Many Requests', 'http.too_many_requests'],
]);

it('renders RFC 9457 responses with the problem+json content type', function () {
    $response = ProblemDetails::forStatus(404, 'The requested resource was not found.')->toResponse();

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($response->getData(true))->toBe([
            'type' => 'about:blank',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'The requested resource was not found.',
            'code' => 'http.not_found',
        ]);
});

it('never exposes exception messages from HTTP exceptions', function () {
    $problem = app(ProblemDetailsRenderer::class)
        ->toProblemDetails(new NotFoundHttpException('The route secret/internal/path could not be found.'));

    expect($problem->detail)->toBe('The requested resource was not found.');
});

it('keeps HTTP exception headers such as Allow', function () {
    $problem = app(ProblemDetailsRenderer::class)
        ->toProblemDetails(new MethodNotAllowedHttpException(['GET', 'HEAD']));

    expect($problem->status)->toBe(405)
        ->and($problem->headers)->toBe(['Allow' => 'GET, HEAD']);
});

it('uses module supplied problem details', function () {
    $problem = app(ProblemDetailsRenderer::class)->toProblemDetails(new ConflictException);

    expect($problem->toArray())->toBe([
        'type' => 'about:blank',
        'title' => 'Conflict',
        'status' => 409,
        'detail' => 'The example already exists.',
        'code' => 'example.already_exists',
    ]);
});

it('maps unknown exceptions to a generic 500', function () {
    $problem = app(ProblemDetailsRenderer::class)->toProblemDetails(new LogicException('SQLSTATE secret'));

    expect($problem->toArray())->toBe([
        'type' => 'about:blank',
        'title' => 'Internal Server Error',
        'status' => 500,
        'detail' => 'An unexpected error occurred.',
        'code' => 'internal.error',
    ]);
});

it('maps 5xx HTTP exceptions without leaking their message', function () {
    $problem = app(ProblemDetailsRenderer::class)
        ->toProblemDetails(new ServiceUnavailableHttpException(null, 'db down at 10.0.0.5'));

    expect($problem->status)->toBe(503)
        ->and($problem->detail)->toBe('The service is temporarily unavailable.');
});

it('leaves exceptions that carry their own response to Laravel', function () {
    $renderer = app(ProblemDetailsRenderer::class);

    expect($renderer->render(new HttpResponseException(new Response('custom', 418))))->toBeNull();
});
