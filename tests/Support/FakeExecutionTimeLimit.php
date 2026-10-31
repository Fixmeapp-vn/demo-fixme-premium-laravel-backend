<?php

namespace Tests\Support;

use App\Platform\Http\ExecutionTimeLimit;

/**
 * Records the requested limit instead of applying it to the test process.
 */
final class FakeExecutionTimeLimit extends ExecutionTimeLimit
{
    /** @var list<int> */
    public array $applied = [];

    public function apply(int $seconds): void
    {
        $this->applied[] = $seconds;
    }
}
