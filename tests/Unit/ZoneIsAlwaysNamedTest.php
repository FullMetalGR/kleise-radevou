<?php

/*
 * A DateTimeImmutable always names its DateTimeZone, on the way in as well as out. A
 * construction that passes none falls back to the process default, which is correct only for as
 * long as the string it parses carries an offset and the application's default stays UTC. A
 * driver, a cast or a column type that stops writing an offset makes every one of them silently
 * wrong by whatever the default happens to be, and nothing fails while it does.
 *
 * The rule is repo-wide, so this reads the tokens of every PHP file the repository owns rather
 * than the files of one change. Both spellings are covered: the constructor, which takes the
 * zone second, and createFromFormat, which takes it third, and so is the constructor written
 * with no argument list at all, which is the shortest way of all to name no zone. A comment or
 * a string naming any of them is words, not a construction.
 */

/**
 * @return list<string>
 */
function phpSourcesUnder(string $directory): array
{
    $files = [];

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        if (is_dir($path)) {
            $files = [...$files, ...phpSourcesUnder($path)];
        } elseif (str_ends_with($entry, '.php')) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

/**
 * Every construction of a date in one source text that names no zone, as "line: spelling".
 *
 * The argument list is walked from the opening parenthesis with a depth counter, so a comma
 * inside a nested call is not a second argument of the outer one, and an empty list is not
 * counted as one argument. A class name followed by no parenthesis at all is the same offence
 * spelled shorter: "new DateTimeImmutable;" is valid PHP, names no zone, and takes the process
 * default, which is the very thing the rule is about.
 *
 * @return list<string>
 */
function zonelessConstructions(string $source): array
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize($source),
        fn (PhpToken $token): bool => ! $token->isIgnorable(),
    ));

    $found = [];

    foreach ($tokens as $index => $token) {
        $spelling = null;
        $least = 0;

        if ($token->is(T_NEW)) {
            $name = $tokens[$index + 1] ?? null;

            if ($name !== null && isDateClass($name->text)) {
                $spelling = 'new '.$name->text;
                $least = 2;
                $index += 2;
            }
        } elseif ($token->is(T_DOUBLE_COLON) && ($tokens[$index + 1] ?? null)?->text === 'createFromFormat') {
            $name = $tokens[$index - 1] ?? null;

            if ($name !== null && isDateClass($name->text)) {
                $spelling = $name->text.'::createFromFormat';
                $least = 3;
                $index += 2;
            }
        }

        if ($spelling === null) {
            continue;
        }

        // The index now stands where the argument list would open. A token that is not the
        // opening parenthesis means there is no list, so nothing was passed at all.
        $opens = ($tokens[$index] ?? null)?->is('(') ?? false;

        if (! $opens || argumentCount($tokens, $index) < $least) {
            $found[] = $token->line.': '.$spelling;
        }
    }

    return $found;
}

function isDateClass(string $name): bool
{
    return in_array(ltrim($name, '\\'), ['DateTime', 'DateTimeImmutable'], true);
}

/**
 * How many arguments the call whose opening parenthesis sits at the index takes.
 *
 * @param  list<PhpToken>  $tokens
 */
function argumentCount(array $tokens, int $open): int
{
    $depth = 0;
    $arguments = 0;

    for ($index = $open; $index < count($tokens); $index++) {
        $token = $tokens[$index];

        if ($token->is(['(', '[', '{'])) {
            $depth++;

            if ($depth === 1 && ! ($tokens[$index + 1] ?? null)?->is(')')) {
                $arguments = 1;
            }

            continue;
        }

        if ($token->is([')', ']', '}'])) {
            $depth--;

            if ($depth === 0) {
                return $arguments;
            }

            continue;
        }

        if ($depth === 1 && $token->is(',')) {
            $arguments++;
        }
    }

    return $arguments;
}

it('builds every date with an explicit zone, everywhere the repository owns PHP', function () {
    $root = dirname(__DIR__, 2);
    $offences = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'tests'] as $directory) {
        foreach (phpSourcesUnder($root.'/'.$directory) as $path) {
            foreach (zonelessConstructions((string) file_get_contents($path)) as $construction) {
                $offences[] = substr($path, strlen($root) + 1).' '.$construction;
            }
        }
    }

    expect($offences)->toBe([]);
});

it('catches a zoneless constructor, a zoneless createFromFormat and a construction with no argument list at all, and passes the ones that name a zone, whatever nesting the arguments carry', function () {
    $source = <<<'PHP'
    <?php

    // new DateTimeImmutable('now') in a comment is words, and so is "new DateTime('now')".
    $bare = new DateTimeImmutable($text);
    $zoned = new DateTimeImmutable($text, new DateTimeZone('UTC'));
    $nested = new DateTimeImmutable(sprintf('%s %s', $day, $time));
    $qualified = new \DateTime($text);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
    $parsedWithZone = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $zone);
    $now = new DateTimeImmutable;
    $passed = stamp(new DateTimeImmutable);
    $other = new SomethingElse($text);
    PHP;

    expect(zonelessConstructions($source))->toBe([
        '4: new DateTimeImmutable',
        '6: new DateTimeImmutable',
        '7: new \DateTime',
        '8: DateTimeImmutable::createFromFormat',
        '10: new DateTimeImmutable',
        '11: new DateTimeImmutable',
    ]);
});
