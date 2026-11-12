<?php

use App\Tenancy\Theme;

it('holds the seven words of settings.theme in their order, neutral the default', function () {
    expect(Theme::words())->toBe(['neutral', 'blue', 'green', 'orange', 'red', 'rose', 'violet'])
        ->and(Theme::DEFAULT)->toBe(Theme::Neutral);
});

it('reads a known word as its theme, {word}', function (string $word) {
    expect(Theme::read($word)->value)->toBe($word);
})->with(['neutral', 'blue', 'green', 'orange', 'red', 'rose', 'violet']);

it('reads an unknown, missing or misshapen word as neutral, {case}', function (mixed $word) {
    expect(Theme::read($word))->toBe(Theme::Neutral);
})->with([
    'null' => [null],
    'empty' => [''],
    'another word' => ['teal'],
    'a capital' => ['Violet'],
    'padded' => [' blue'],
    'a number' => [3],
]);

it('maps a null or unreadable accent to neutral, {case}', function (?string $accent) {
    expect(Theme::nearest($accent))->toBe(Theme::Neutral);
})->with([
    'null' => [null],
    'empty' => [''],
    'a name' => ['red'],
    'no hash' => ['7c3aed'],
    'five digits' => ['#7c3ae'],
    'not hex' => ['#zzzzzz'],
]);

it('maps a grey, a black or a white to neutral, {accent}', function (string $accent) {
    expect(Theme::nearest($accent))->toBe(Theme::Neutral);
})->with(['#000000', '#fff', '#FFFFFF', '#808080', '#171717', '#333338', '#f5f5f4']);

it('maps each theme\'s own light primary to that theme', function (string $accent, Theme $theme) {
    expect(Theme::nearest($accent))->toBe($theme);
})->with([
    ['#2563eb', Theme::Blue],
    ['#15803d', Theme::Green],
    ['#c2410c', Theme::Orange],
    ['#dc2626', Theme::Red],
    ['#e11d48', Theme::Rose],
    ['#7c3aed', Theme::Violet],
]);

it('maps a coloured accent to the theme whose light primary is nearest in hue, {accent}', function (string $accent, Theme $theme) {
    expect(Theme::nearest($accent))->toBe($theme);
})->with([
    'teal-blue' => ['#0E7490', Theme::Blue],
    'cyan' => ['#00bcd4', Theme::Blue],
    'teal' => ['#008080', Theme::Green],
    'jade' => ['#00a86b', Theme::Green],
    'gold' => ['#ffd700', Theme::Orange],
    'safety orange' => ['#ff6600', Theme::Orange],
    'pure red' => ['#ff0000', Theme::Red],
    'pink' => ['#e91e63', Theme::Rose],
    'magenta' => ['#ff00ff', Theme::Violet],
    'navy' => ['#172554', Theme::Blue],
    'short violet' => ['#93f', Theme::Violet],
]);
