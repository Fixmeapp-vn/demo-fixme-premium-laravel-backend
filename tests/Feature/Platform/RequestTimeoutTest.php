<?php

use App\Platform\Http\ExecutionTimeLimit;
use Tests\Support\FakeExecutionTimeLimit;

beforeEach(function () {
    $this->limit = app(ExecutionTimeLimit::class);

    expect($this->limit)->toBeInstanceOf(FakeExecutionTimeLimit::class);
});

it('applies the default request timeout', function () {
    $this->get('/health/live')->assertOk();

    expect($this->limit->applied)->toBe([30]);
});

it('applies the configured request timeout', function () {
    config(['platform.http.request_timeout' => '5']);

    $this->get('/health/live')->assertOk();

    expect($this->limit->applied)->toBe([5]);
});

it('applies the timeout to unmatched routes too', function () {
    $this->get('/nope')->assertNotFound();

    expect($this->limit->applied)->toBe([30]);
});
