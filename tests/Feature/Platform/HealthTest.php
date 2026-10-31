<?php

it('reports liveness without external dependencies', function () {
    $this->getJson('/health/live')
        ->assertOk()
        ->assertExactJson(['status' => 'ok'])
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('answers HEAD probes', function () {
    $this->head('/health/live')->assertOk();
});
