<?php

use App\Infrastructure\Db\TextArray;

it('decodes {literal}', function (string $literal, array $expected) {
    expect(TextArray::decode($literal))->toBe($expected);
})->with([
    'an empty array' => ['{}', []],
    'one element' => ['{staff}', ['staff']],
    'two elements' => ['{staff,events}', ['staff', 'events']],
    'quoted elements' => ['{"with space","a,b","{brace}"}', ['with space', 'a,b', '{brace}']],
    'escaped quotes and backslashes' => ['{"quo\"te","back\\\\slash"}', ['quo"te', 'back\\slash']],
    'the string NULL' => ['{"NULL"}', ['NULL']],
    'an empty string' => ['{""}', ['']],
    'a null element, which is dropped' => ['{NULL,staff}', ['staff']],
]);

it('encodes a list as a literal with every element quoted', function () {
    expect(TextArray::encode([]))->toBe('{}')
        ->and(TextArray::encode(['staff']))->toBe('{"staff"}')
        ->and(TextArray::encode(['a b', 'c,d', '{e}']))->toBe('{"a b","c,d","{e}"}')
        ->and(TextArray::encode(['quo"te', 'back\\slash']))->toBe('{"quo\"te","back\\\\slash"}');
});

it('round-trips awkward values', function () {
    $values = ['', 'NULL', 'a b', 'c,d', 'quo"te', 'back\\slash', '{}', 'plain'];

    expect(TextArray::decode(TextArray::encode($values)))->toBe($values);
});

it('refuses a literal that is not an array', function () {
    expect(fn () => TextArray::decode('staff'))->toThrow(InvalidArgumentException::class);
});
