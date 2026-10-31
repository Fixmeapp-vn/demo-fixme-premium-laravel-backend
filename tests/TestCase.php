<?php

namespace Tests;

use App\Platform\Http\ExecutionTimeLimit;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeExecutionTimeLimit;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Never put a real PHP time limit on the test process.
        $this->app->instance(ExecutionTimeLimit::class, new FakeExecutionTimeLimit);
    }
}
