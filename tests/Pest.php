<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every test runs against a booted application (config, container, logging),
| so platform config validation is exercised on each boot as well.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit');
