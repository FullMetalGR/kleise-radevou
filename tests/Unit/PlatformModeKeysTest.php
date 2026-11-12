<?php

declare(strict_types=1);

use App\Application\Payment\PlatformMode;

/*
 * The platform's keys reach PlatformMode::of as the raw configuration, so a trace taken in any
 * frame below it would print them unless the parameter is marked sensitive.
 */

it('marks the configured keys sensitive where they enter, so no trace through of() prints them', function () {
    $parameter = (new ReflectionMethod(PlatformMode::class, 'of'))->getParameters()[0] ?? null;

    expect($parameter?->getName())->toBe('configured')
        ->and($parameter?->getAttributes(SensitiveParameter::class))->toHaveCount(1);
});
