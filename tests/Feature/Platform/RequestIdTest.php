<?php

use App\Platform\Http\RequestId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/api/v1/_test/request-id', fn (Request $request) => [
        'attribute' => RequestId::from($request),
        'context' => Context::get('request_id'),
    ]);
    Route::get('/api/v1/_test/job-context', fn () => [
        // What a job dispatched now would carry in its payload.
        'job_context' => array_keys(Context::dehydrate()['data'] ?? []),
    ]);
});

it('accepts a valid incoming request id and echoes it', function () {
    $this->getJson('/api/v1/_test/request-id', ['X-Request-ID' => 'edge-01:abc.123'])
        ->assertHeader('X-Request-ID', 'edge-01:abc.123')
        ->assertExactJson(['attribute' => 'edge-01:abc.123', 'context' => 'edge-01:abc.123']);
});

it('generates an id when none is sent', function () {
    $response = $this->getJson('/api/v1/_test/request-id');

    $id = $response->headers->get('X-Request-ID');

    expect(RequestId::isValid($id))->toBeTrue()
        ->and($response->json())->toBe(['attribute' => $id, 'context' => $id]);
});

it('replaces invalid incoming ids', function (string $incoming) {
    $id = $this->getJson('/api/v1/_test/request-id', ['X-Request-ID' => $incoming])
        ->headers->get('X-Request-ID');

    expect($id)->not->toBe($incoming)
        ->and(RequestId::isValid($id))->toBeTrue();
})->with([
    'too long' => str_repeat('a', 129),
    'unsafe characters' => '<script>alert(1)</script>',
    'whitespace' => 'abc def',
]);

it('adds the request id to error responses', function () {
    $this->get('/missing', ['X-Request-ID' => 'trace-me'])
        ->assertNotFound()
        ->assertHeader('X-Request-ID', 'trace-me');
});

it('propagates the request id to queued jobs through Context', function () {
    $this->getJson('/api/v1/_test/job-context', ['X-Request-ID' => 'job-propagation'])
        ->assertOk()
        ->assertJsonPath('job_context', fn (array $keys) => in_array('request_id', $keys, true));
});
