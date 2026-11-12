<?php

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Tests\TestCase;

test('a unit test binds to the plain PHPUnit test case', function () {
    expect($this)->not->toBeInstanceOf(TestCase::class);
});

test('a unit test never boots the application', function () {
    expect(Container::getInstance())->not->toBeInstanceOf(Application::class);
});
