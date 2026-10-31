<?php

use App\Platform\Http\RequestId;

it('accepts ids from the documented safe character set up to 128 characters', function (string $id) {
    expect(RequestId::isValid($id))->toBeTrue();
})->with([
    'uuid' => '0192f0a4-5b1e-7c3d-9e8f-0123456789ab',
    'dotted' => 'edge.gw-01:req_42',
    'max length' => str_repeat('a', 128),
]);

it('rejects ids that are empty, too long or contain unsafe characters', function (mixed $id) {
    expect(RequestId::isValid($id))->toBeFalse();
})->with([
    'empty' => '',
    'too long' => str_repeat('a', 129),
    'space' => 'abc def',
    'newline' => "abc\ndef",
    'quote' => 'abc"def',
    'slash' => 'abc/def',
    'non string' => [123],
    'null' => [null],
]);

it('generates valid unique ids', function () {
    $a = RequestId::generate();
    $b = RequestId::generate();

    expect(RequestId::isValid($a))->toBeTrue()
        ->and($a)->not->toBe($b)
        ->and($a)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});
