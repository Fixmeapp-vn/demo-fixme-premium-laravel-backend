<?php

use Tests\Support\Artisan;

it('boots and reports the environment', function () {
    $process = Artisan::process(['about', '--only=environment']);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($process->getOutput())->toContain('testing');
});

it('fails to start with a non-zero exit code and every configuration error', function () {
    $process = Artisan::process(['about'], [
        'APP_ENV' => 'qa',
        'HTTP_REQUEST_TIMEOUT' => '0',
        'METRICS_ADDR' => 'not-an-address',
    ]);
    $process->run();

    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->not->toBe(0)
        ->and($output)
        ->toContain('Invalid application configuration')
        ->toContain('APP_ENV must be one of')
        ->toContain('HTTP_REQUEST_TIMEOUT must be an integer number of seconds between 1 and 300.')
        ->toContain('METRICS_ADDR must be a "host:port" address');
});

it('does not log secret configuration values on boot failure', function () {
    $process = Artisan::process(['about'], [
        'APP_ENV' => 'production',
        'APP_KEY' => 'base64:c3VwZXItc2VjcmV0LWtleS0xMjM0NTY3ODkwYWJjZGVm',
        'HTTP_REQUEST_TIMEOUT' => '0',
    ]);
    $process->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput().$process->getErrorOutput())
        ->not->toContain('c3VwZXItc2VjcmV0LWtleS0xMjM0NTY3ODkwYWJjZGVm');
});

it('still allows setup commands before a valid configuration exists', function () {
    $process = Artisan::process(['package:discover'], ['APP_ENV' => 'production', 'APP_KEY' => '']);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());
});
