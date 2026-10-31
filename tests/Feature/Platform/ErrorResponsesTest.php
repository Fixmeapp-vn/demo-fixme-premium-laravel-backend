<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Support\ConflictException;

beforeEach(function () {
    Route::get('/api/v1/_test/boom', fn () => throw new RuntimeException('SQLSTATE[08006] password=hunter2'));
    Route::get('/api/v1/_test/conflict', fn () => throw new ConflictException);
    Route::post('/api/v1/_test/validate', fn (Request $request) => $request->validate(['email' => ['required', 'email']]));
    Route::get('/api/v1/_test/ok', fn () => ['ok' => true]);
});

it('returns 404 Problem Details for unknown routes', function () {
    $this->get('/does-not-exist')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson([
            'type' => 'about:blank',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'The requested resource was not found.',
            'code' => 'http.not_found',
        ]);
});

it('returns 405 Problem Details with an Allow header for unsupported methods', function () {
    $response = $this->post('/health/live');

    $response->assertStatus(405)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson([
            'title' => 'Method Not Allowed',
            'status' => 405,
            'code' => 'http.method_not_allowed',
        ]);

    expect($response->headers->get('Allow'))->toContain('GET')->toContain('HEAD');
});

it('returns 500 Problem Details for unhandled exceptions without leaking internals', function () {
    $response = $this->get('/api/v1/_test/boom');

    $response->assertStatus(500)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson([
            'type' => 'about:blank',
            'title' => 'Internal Server Error',
            'status' => 500,
            'detail' => 'An unexpected error occurred.',
            'code' => 'internal.error',
        ]);

    expect($response->getContent())->not->toContain('SQLSTATE')->not->toContain('hunter2');
});

it('never returns stack traces even when debug mode is on', function () {
    config(['app.debug' => true]);

    $response = $this->get('/api/v1/_test/boom')->assertStatus(500);

    expect($response->json())->not->toHaveKeys(['trace', 'exception', 'file', 'line'])
        ->and($response->getContent())->not->toContain('SQLSTATE');
});

it('logs unhandled exceptions with their details', function () {
    Log::spy();

    $this->get('/api/v1/_test/boom')->assertStatus(500);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'SQLSTATE')
            && ($context['exception'] ?? null) instanceof RuntimeException)
        ->once();
});

it('keeps serving requests after an exception', function () {
    $this->get('/api/v1/_test/boom')->assertStatus(500);
    $this->get('/api/v1/_test/ok')->assertOk()->assertExactJson(['ok' => true]);
});

it('renders validation errors as Problem Details', function () {
    $this->postJson('/api/v1/_test/validate', ['email' => 'nope'])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson([
            'status' => 422,
            'code' => 'validation.failed',
            'detail' => 'The given data was invalid.',
        ])
        ->assertJsonValidationErrors(['email']);
});

it('renders module-specific error codes', function () {
    $this->get('/api/v1/_test/conflict')
        ->assertStatus(409)
        ->assertJson(['code' => 'example.already_exists', 'status' => 409]);
});
