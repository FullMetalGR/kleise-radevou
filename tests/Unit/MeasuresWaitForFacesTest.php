<?php

/*
 * A script the browser suite keeps in its support classes waits for the page's faces before it
 * reads a size or a position. The admin and the console draw their text in a fallback face until
 * their own two arrive, and the same words are another width in another face, so a box read
 * before then belongs to a layout that is about to move, and the reading passes or fails by
 * which side of the swap it fell on. On a phone a page too wide also widens the window's own
 * figures, so there no box and no window size is safe to read early, a fixed one included.
 *
 * The promise waited on is the browser's own. Asked for, it lays the page out first, so text
 * that entered the page a moment ago has asked for its face before the promise is handed over,
 * and the reading behind it is of the layout that stays.
 *
 * This reads the string constants of every class under tests/Browser/Support. A script a method
 * builds, and one a test file spells for itself, is not read.
 */

/** The properties and calls that answer a size or a position the layout computed. */
const LAYOUT_READS = '/\b(?:scrollWidth|scrollHeight|clientWidth|clientHeight|offsetWidth|offsetHeight|offsetLeft|offsetTop|innerWidth|innerHeight|getBoundingClientRect|elementFromPoint|elementsFromPoint)\b/';

/** How a script that waits begins, so nothing in it is read before the faces are in. */
const WAITS_FOR_FACES = 'document.fonts.ready.then(';

/**
 * Every script constant of the support classes that reads a size or a position, by its class
 * and name.
 *
 * @return array<string, string>
 */
function supportScriptsThatMeasure(): array
{
    $scripts = [];

    foreach (scandir(dirname(__DIR__).'/Browser/Support') ?: [] as $entry) {
        $class = 'Tests\\Browser\\Support\\'.substr($entry, 0, -4);

        if (! str_ends_with($entry, '.php') || ! class_exists($class)) {
            continue;
        }

        foreach ((new ReflectionClass($class))->getConstants() as $name => $script) {
            if (is_string($script) && preg_match(LAYOUT_READS, $script) === 1) {
                $scripts[substr($entry, 0, -4).'::'.$name] = $script;
            }
        }
    }

    ksort($scripts);

    return $scripts;
}

it('waits for the page\'s faces in every support script that reads a size or a position', function () {
    $scripts = supportScriptsThatMeasure();
    $early = array_keys(array_filter($scripts, fn (string $script): bool => ! str_starts_with(ltrim($script), WAITS_FOR_FACES)));

    expect($scripts)->not->toBeEmpty()
        ->and($early)->toBe([]);
});

it('counts a box, a scroll size and the window\'s own size as readings, and leaves a style or a count of boxes alone', function () {
    expect(preg_match(LAYOUT_READS, 'frame.scrollWidth > frame.clientWidth'))->toBe(1)
        ->and(preg_match(LAYOUT_READS, 'bubble.getBoundingClientRect().left >= 0'))->toBe(1)
        ->and(preg_match(LAYOUT_READS, 'window.innerWidth'))->toBe(1)
        ->and(preg_match(LAYOUT_READS, "getComputedStyle(frame).overflowX === 'auto'"))->toBe(0)
        ->and(preg_match(LAYOUT_READS, 'tab.getClientRects().length > 0'))->toBe(0);
});
