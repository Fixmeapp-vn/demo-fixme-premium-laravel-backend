<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/api/v1/_test/items/{item}', fn (string $item) => ['item' => $item]);
    Route::get('/api/v1/_test/boom', fn () => throw new RuntimeException('boom'));
    Log::spy();
});

it('logs the route template, status, duration and request id', function () {
    $this->get('/api/v1/_test/items/secret-value?token=abc', ['X-Request-ID' => 'access-1'])->assertOk();

    Log::shouldHaveReceived('log')
        ->withArgs(fn (string $level, string $message, array $context) => $level === 'info'
            && $message === 'http.request'
            && $context['method'] === 'GET'
            && $context['route'] === '/api/v1/_test/items/{item}'
            && $context['status'] === 200
            && $context['request_id'] === 'access-1'
            && is_float($context['duration_ms']))
        ->once();
});

it('never logs the raw path or query string', function () {
    $this->get('/api/v1/_test/items/secret-value?token=abc')->assertOk();

    Log::shouldHaveReceived('log')
        ->withArgs(fn (string $level, string $message, array $context) => $message === 'http.request'
            && ! str_contains(json_encode($context), 'secret-value')
            && ! str_contains(json_encode($context), 'token'))
        ->once();
});

it('uses a fixed route label for unmatched requests', function () {
    $this->get('/no/such/path/123')->assertNotFound();

    Log::shouldHaveReceived('log')
        ->withArgs(fn (string $level, string $message, array $context) => $level === 'warning'
            && $context['route'] === 'unmatched'
            && $context['status'] === 404)
        ->once();
});

it('logs failed requests at error level', function () {
    $this->get('/api/v1/_test/boom')->assertStatus(500);

    Log::shouldHaveReceived('log')
        ->withArgs(fn (string $level, string $message, array $context) => $level === 'error'
            && $message === 'http.request'
            && $context['status'] === 500)
        ->once();
});

it('logs probe endpoints at debug level', function () {
    $this->get('/health/live')->assertOk();

    Log::shouldHaveReceived('log')
        ->withArgs(fn (string $level, string $message, array $context) => $level === 'debug'
            && $context['route'] === '/health/live')
        ->once();
});
