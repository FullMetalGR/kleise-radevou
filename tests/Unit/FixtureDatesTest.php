<?php

/*
 * A test's dates and its clock agree, in one of two regimes. A test that spells a date literally
 * binds a pinned clock, FixedClock or HaltedClock, before anything reads the time; a test left on
 * the system clock spells its dates relative to today. A literal date under the system clock is a
 * test whose answer changes on the day real time reaches the date: a cancel that was outside its
 * policy window is inside it, a hold that was past its lead time is behind it, and nothing in the
 * test moved.
 *
 * This walks the three suites that boot the application, Feature, Concurrency and Browser,
 * where the system clock is bound until a test binds another, and reads the tokens of every
 * file. A file spells a fixture date when a string in it carries a calendar date, in the ISO
 * form the suites write, the dd/mm/yyyy the admin's screens render or the day, month and year a
 * mail spells out, when it reads FixtureDay, or when it calls a helper whose own signature
 * reads FixtureDay and so starts on the fixture's date unless told otherwise. Such a file constructs a pinned clock or calls
 * FixtureDay::pinClock(), or it is named below with the reason no clock is asked about its
 * dates. A comment naming a date is words, and so is an import nothing uses.
 *
 * The Unit suite is held by a rule of its own, no unit test reads the real clock: it boots
 * nothing, so no clock is bound there to pin, and a unit test hands its now in by hand. The
 * last case holds that against the production clock's name, a date built from a string that
 * leaves its day to today, a static now() and the functions that answer the wall clock.
 *
 * The walk reads files, not tests: a file that pins a clock in one test and leaves another on the
 * system clock with a literal date passes here. A by-hand run of the unit and feature suites with
 * the application clock sixty days ahead is what finds those, and it is the run to repeat when in
 * doubt.
 */

/**
 * The files of the booted suites that spell a date and pin no clock, each with the reason no
 * clock is asked about the dates it spells. A file earns a line here by its reason, never by
 * being red.
 */
const FIXTURE_DATE_EXCEPTIONS = [
    'tests/Browser/AdminStaffTest.php' => 'the demo seeder\'s own Christmas closure, 25/12/2026, read back from the closures list, which filters by no date; the row is the seeder\'s literal and no clock is asked',
    'tests/Concurrency/ExclusionConstraintTest.php' => 'rows written straight to the tables to meet the exclusion constraint; no use case runs and no clock is asked',
    'tests/Concurrency/ExpireHoldsTest.php' => 'its one date seeds the starts of rows the sweeper reclaims by hold_expires_at against the database\'s now(); the starts meet no clock',
    'tests/Concurrency/LockManagerTest.php' => 'lock keys named by staff and day; a key is text and no clock is asked',
    'tests/Concurrency/TransactionRunnerTest.php' => 'staff-day and item rows written straight to the tables under the runner; no policy and no clock is asked',
    'tests/Feature/Admin/BookingsScreenTest.php' => 'rows written straight to the tables and listed by the range and the order the request names; no answer the file reads is the clock\'s',
    'tests/Feature/Admin/ServicesScreenTest.php' => 'one cancelled row on the fixture\'s date, there for the delete to find a booking history; a delete asks no clock',
    'tests/Feature/Admin/SharedPropsTest.php' => 'a grace end and a trial end stored on the tenant row, the grace end read back as a prop; no clock is asked',
    'tests/Feature/Admin/StoredEntitlementsTest.php' => 'closure dates and a stored grace end met by the subscription gate, which reads the tenant row and no clock',
    'tests/Feature/Admin/SubscriptionGateTest.php' => 'a stored grace end and stored closing instants beside the state the gate reads, the day a closing window ends spelled from the instant; the gate reads the columns and no clock',
    'tests/Feature/ApiErrorEnvelopeTest.php' => 'a date handed to an exception the test throws itself, to read the envelope it renders as; no use case runs',
    'tests/Feature/ApiSurfaceTest.php' => 'a snapshot built by hand for the payload\'s shape; nothing is booked and no clock is asked',
    'tests/Feature/ApprovalRequestSeamTest.php' => 'the system clock on purpose: the links expire with a hold the database dated, and every seamHold is handed a start from firstWindowDay',
    'tests/Feature/AvailabilityRepositoryTest.php' => 'the repository reads masks for the days the test passes in; the read takes its range as arguments and asks no clock',
    'tests/Feature/BusinessRecipientsTest.php' => 'rows written straight to the tables for the recipient rule to read; no clock is asked',
    'tests/Feature/ConnectionZoneTest.php' => 'the framework\'s own clock moved by travelTo, to prove what the connection\'s zone does to a model timestamp; the Clock port is not in it',
    'tests/Feature/Language/BusinessMailLanguageTest.php' => 'the system clock on purpose: the decision links the approval request carries are minted and read against it, and its one LunaFixture::hold is handed a start from firstWindowDay',
    'tests/Feature/Language/DecisionPagesLanguageTest.php' => 'the system clock on purpose: the decision links are minted and read against it, and every LunaFixture::hold is handed a start from firstWindowDay',
    'tests/Feature/Language/PageTwinsTest.php' => 'the system clock on purpose: the My bookings, decision and payment links are minted and read against it, and every LunaFixture::booking and hold is handed a start from firstWindowDay',
    'tests/Feature/NoteSeamTest.php' => 'the system clock on purpose: the confirm queues a reminder the sync queue runs against the real clock, and every seamHold is handed a start from firstWindowDay',
    'tests/Feature/TenantSchemaTest.php' => 'rows written straight to the tables to meet the schema\'s constraints; no use case runs',
];

/**
 * A calendar date as a string spells it. ISO, whole or as the head of a pattern a sprintf fills
 * in, 2026-11-03 and 2026-12-%02d; the admin's screens, 04/12/2026; a mail or an outcome page,
 * 3 November 2026. A date format such as d/m/Y, a path and a day with no year are none of them.
 */
const FIXTURE_DATE_FORMS = '~(?<![0-9])(?:19|20)[0-9]{2}-(?:[0-9]{2}-(?:[0-9]{2}|%)|%)'
    .'|(?<![0-9/])[0-9]{2}/[0-9]{2}/(?:19|20)[0-9]{2}(?![0-9/])'
    .'|(?<![0-9])[0-9]{1,2} (?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]* (?:19|20)[0-9]{2}(?![0-9])~';

/**
 * @return list<string> every PHP file under the directory, as a path from the repository root
 */
function fixtureDateSources(string $directory): array
{
    $files = [];

    foreach (scandir(base_path_of_tests().'/'.$directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        if (is_dir(base_path_of_tests().'/'.$path)) {
            $files = [...$files, ...fixtureDateSources($path)];
        } elseif (str_ends_with($entry, '.php')) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

/**
 * The repository root, without the container the Unit suite does not have.
 */
function base_path_of_tests(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return list<PhpToken> the tokens of a source with whitespace and comments dropped
 */
function fixtureDateTokens(string $source): array
{
    return array_values(array_filter(
        PhpToken::tokenize($source),
        fn (PhpToken $token): bool => ! $token->isIgnorable(),
    ));
}

/**
 * Whether a token names the class given, bare or through its namespace.
 */
function fixtureDateNames(PhpToken $token, string $class): bool
{
    return $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
        && ($token->text === $class || str_ends_with($token->text, '\\'.$class));
}

/**
 * The helpers that start on the fixture's date unless told otherwise: every function and
 * method of the sources given whose parameter list reads FixtureDay. Read off the signatures,
 * so a helper gaining the default becomes one without being listed anywhere.
 *
 * @param  list<string>  $sources
 * @return list<string>
 */
function fixtureDateCarriers(array $sources): array
{
    $carriers = [];

    foreach ($sources as $source) {
        $tokens = fixtureDateTokens($source);

        foreach ($tokens as $index => $token) {
            $name = $tokens[$index + 1] ?? null;

            if (! $token->is(T_FUNCTION) || $name === null || ! $name->is(T_STRING) || ($tokens[$index + 2] ?? null)?->text !== '(') {
                continue;
            }

            $depth = 0;

            for ($at = $index + 2; isset($tokens[$at]); $at++) {
                $depth += match ($tokens[$at]->text) {
                    '(', '[' => 1,
                    ')', ']' => -1,
                    default => 0,
                };

                if ($depth === 0) {
                    break;
                }

                if (fixtureDateNames($tokens[$at], 'FixtureDay')) {
                    $carriers[] = $name->text;

                    break;
                }
            }
        }
    }

    sort($carriers);

    return array_values(array_unique($carriers));
}

/**
 * What in a source spells a fixture date, as "line: spelling": every calendar date a string
 * carries, in any of FIXTURE_DATE_FORMS, a read of FixtureDay other than the pin itself, and a
 * call of a carrier.
 *
 * @param  list<string>  $carriers
 * @return list<string>
 */
function fixtureDatesSpelled(string $source, array $carriers): array
{
    $tokens = fixtureDateTokens($source);
    $found = [];

    foreach ($tokens as $index => $token) {
        $next = $tokens[$index + 1] ?? null;
        $before = $tokens[$index - 1] ?? null;
        $member = isset($tokens[$index + 2]) ? $tokens[$index + 2]->text : '';

        if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) && preg_match_all(FIXTURE_DATE_FORMS, $token->text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$date, $offset]) {
                $found[] = sprintf('%d: %s', $token->line + substr_count($token->text, "\n", 0, $offset), $date);
            }
        } elseif (fixtureDateNames($token, 'FixtureDay') && $next?->is(T_DOUBLE_COLON) === true && $member !== 'pinClock') {
            $found[] = sprintf('%d: FixtureDay::%s', $token->line, $member);
        } elseif ($token->is(T_STRING) && in_array($token->text, $carriers, true) && $next?->text === '(' && $before?->is([T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) !== true) {
            $found[] = sprintf('%d: %s()', $token->line, $token->text);
        }
    }

    return $found;
}

/**
 * Whether a source pins a clock: it constructs FixedClock or HaltedClock, calls a named
 * constructor of one, or calls FixtureDay::pinClock(). An import alone pins nothing.
 */
function fixtureDatePinned(string $source): bool
{
    $tokens = fixtureDateTokens($source);

    foreach ($tokens as $index => $token) {
        $next = $tokens[$index + 1] ?? null;
        $before = $tokens[$index - 1] ?? null;

        if ((fixtureDateNames($token, 'FixedClock') || fixtureDateNames($token, 'HaltedClock')) && ($before?->is(T_NEW) === true || $next?->is(T_DOUBLE_COLON) === true)) {
            return true;
        }

        if (fixtureDateNames($token, 'FixtureDay') && $next?->is(T_DOUBLE_COLON) === true && ($tokens[$index + 2] ?? null)?->text === 'pinClock') {
            return true;
        }
    }

    return false;
}

/**
 * Whether a date string leaves its day to the real clock: it names no calendar date and is no
 * '@' timestamp, so 'now', 'today', 'tomorrow', '+1 day', 'midnight' and a bare '10:00' all do.
 * A string that names its day and then moves it, '2026-11-03 10:00 +1 day', reads no clock.
 */
function leavesItsDayToTheRealClock(string $literal): bool
{
    $text = substr($literal, 1, -1);

    return preg_match('/(?<![0-9])[0-9]{4}-[0-9]{2}-[0-9]{2}(?![0-9])/', $text) !== 1 && ! str_starts_with($text, '@');
}

/**
 * Where a source reads the real clock, as "line: spelling": the production clock by name, a
 * date constructed with no string at all or from one that leaves its day to today, a static
 * now(), today(), tomorrow() or yesterday() on any class, and the functions that answer the
 * wall clock. A constructor handed a variable is passed: what it reads is the caller's to say.
 *
 * @return list<string>
 */
function realClockReads(string $source): array
{
    $tokens = fixtureDateTokens($source);
    $found = [];

    foreach ($tokens as $index => $token) {
        $next = $tokens[$index + 1] ?? null;
        $before = $tokens[$index - 1] ?? null;
        $called = $next?->text === '(';
        $free = $before?->is([T_FUNCTION, T_NEW, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON]) !== true;
        $constructs = $before?->is(T_NEW) === true && array_any(['DateTimeImmutable', 'DateTime', 'Carbon', 'CarbonImmutable'], fn (string $class): bool => fixtureDateNames($token, $class));
        $creates = $token->is(T_STRING) && in_array($token->text, ['date_create', 'date_create_immutable'], true) && $called && $free;
        // The first argument, past its name where the call names it.
        $first = $index + (($tokens[$index + 3] ?? null)?->text === ':' ? 4 : 2);
        $argument = $called ? ($tokens[$first] ?? null) : null;
        $whole = in_array(($tokens[$first + 1] ?? null)?->text, [',', ')'], true);

        if (fixtureDateNames($token, 'SystemClock')) {
            $found[] = sprintf('%d: SystemClock', $token->line);
        } elseif (($constructs || $creates) && (! $called || $argument?->text === ')')) {
            $found[] = sprintf('%d: %s%s()', $token->line, $constructs ? 'new ' : '', $token->text);
        } elseif (($constructs || $creates) && $argument?->is(T_CONSTANT_ENCAPSED_STRING) === true && $whole && leavesItsDayToTheRealClock($argument->text)) {
            $found[] = sprintf('%d: %s%s(%s)', $token->line, $constructs ? 'new ' : '', $token->text, $argument->text);
        } elseif ($token->is(T_STRING) && in_array($token->text, ['now', 'today', 'tomorrow', 'yesterday'], true) && $called && $before?->is(T_DOUBLE_COLON) === true) {
            $found[] = sprintf('%d: %s::%s()', $token->line, ($tokens[$index - 2] ?? null)?->text, $token->text);
        } elseif ($token->is(T_STRING) && in_array($token->text, ['time', 'microtime', 'date', 'gmdate', 'strtotime', 'now', 'today'], true) && $called && $free) {
            $found[] = sprintf('%d: %s()', $token->line, $token->text);
        }
    }

    return $found;
}

it('pins a clock in every file of the booted suites that spells a fixture date, or names the file with its reason', function () {
    $read = fn (string $path): string => (string) file_get_contents(base_path_of_tests().'/'.$path);
    $carriers = fixtureDateCarriers(array_map($read, fixtureDateSources('tests')));
    $unpinned = [];

    foreach ([...fixtureDateSources('tests/Feature'), ...fixtureDateSources('tests/Concurrency'), ...fixtureDateSources('tests/Browser')] as $path) {
        $source = $read($path);
        $spelled = fixtureDatesSpelled($source, $carriers);

        if ($spelled !== [] && ! fixtureDatePinned($source)) {
            $unpinned[$path] = $spelled[0];
        }
    }

    $excepted = array_keys(FIXTURE_DATE_EXCEPTIONS);
    sort($excepted);

    expect($carriers)->toContain('hold', 'booking', 'seamHold')
        ->and(array_diff_key($unpinned, FIXTURE_DATE_EXCEPTIONS))->toBe([], 'a file spells a fixture date and pins no clock')
        ->and(array_values(array_diff($excepted, array_keys($unpinned))))->toBe([], 'an exception names a file that no longer needs one');
});

it('catches a literal date as the suites spell it and the screens render it, ISO, the admin\'s dd/mm/yyyy and a mail\'s day, month and year, a sprintf pattern of one, a read of the fixture\'s constant and a call of a carrier, and passes a comment, a date format, a file that pins a clock and an import nothing uses as what they are', function () {
    $carriers = fixtureDateCarriers([
        '<?php function seamHold(string $service, string $start = FixtureDay::START): array { return []; }',
        '<?php final class LunaFixture { public static function hold(array $services = [\'Haircut\'], string $start = FixtureDay::START): string { return \'\'; } public static function row(string $uuid): array { return []; } }',
        '<?php function elsewhere(string $start = \'10:00\'): void {}',
    ]);

    expect($carriers)->toBe(['hold', 'seamHold'])
        ->and(fixtureDatesSpelled('<?php $start = new DateTimeImmutable(\'2026-11-03 10:00\', $zone);', $carriers))->toBe(['1: 2026-11-03'])
        ->and(fixtureDatesSpelled("<?php\n\$day = sprintf('2026-12-%02d 10:00', \$n);", $carriers))->toBe(['2: 2026-12-%'])
        ->and(fixtureDatesSpelled("<?php\n\$page->assertSeeIn('.admin-blackouts', '25/12/2026')\n    ->assertSeeIn('.admin-staff-view__closures', '04/12/2026');", $carriers))->toBe(['2: 25/12/2026', '3: 04/12/2026'])
        ->and(fixtureDatesSpelled('<?php expect($body)->toContain(\'It was on Tuesday 3 November 2026 at 10:00\')->toContain(\'12 Dec 2026\');', $carriers))->toBe(['1: 3 November 2026', '1: 12 Dec 2026'])
        ->and(fixtureDatesSpelled('<?php $page->assertSeeIn(\'.admin-blackouts\', $day->format(\'d/m/Y\'))->assertPathIs(\'/admin/staff/12\')->assertSee(\'3 November at 10:00\')->assertSee(\'1/2/2026\');', $carriers))->toBe([])
        ->and(fixtureDatesSpelled('<?php $uuid = LunaFixture::booking(\'confirmed\', start: FixtureDay::START);', $carriers))->toBe(['1: FixtureDay::START'])
        ->and(fixtureDatesSpelled('<?php $uuid = LunaFixture::hold(); [$url, $body] = seamHold(\'Haircut\');', $carriers))->toBe(['1: hold()', '1: seamHold()'])
        ->and(fixtureDatesSpelled("<?php\n// 2026-11-03 is a Tuesday\n/* and 2026-11-04 a Wednesday */\n\$row = LunaFixture::row(\$uuid); \$this->hold(); FixtureDay::pinClock();", $carriers))->toBe([])
        ->and(fixtureDatesSpelled('<?php $id = \'7b7f0e5a-0000-4000-8000-000000000005\'; $phone = \'+30 210 1234567\';', $carriers))->toBe([])
        ->and(fixtureDatePinned('<?php app()->instance(Clock::class, FixedClock::at(\'2026-11-02 06:00:00\'));'))->toBeTrue()
        ->and(fixtureDatePinned('<?php $job->handle(new HaltedClock);'))->toBeTrue()
        ->and(fixtureDatePinned('<?php $job->handle(new \Tests\Support\FixedClock($now));'))->toBeTrue()
        ->and(fixtureDatePinned('<?php beforeEach(fn () => FixtureDay::pinClock());'))->toBeTrue()
        ->and(fixtureDatePinned("<?php\nuse Tests\\Support\\FixedClock;\nuse Tests\\Support\\FixtureDay;\n\$start = FixtureDay::START; // FixedClock::at()"))->toBeFalse();
});

it('reads the real clock in no unit test, by the production clock\'s name, a date string that leaves its day to today, a static now() or a wall-clock function, since the Unit suite binds no clock and hands its now in by hand', function () {
    $reads = [];

    foreach (fixtureDateSources('tests/Unit') as $path) {
        $found = realClockReads((string) file_get_contents(base_path_of_tests().'/'.$path));

        if ($found !== []) {
            $reads[$path] = $found;
        }
    }

    $sample = <<<'PHP'
        <?php
        $clock = new SystemClock;
        $now = new DateTimeImmutable('now', $utc);
        $stamp = time();
        $sql = 'updated_at = now()';
        $at = $clock->now();
        $then = new DateTimeImmutable('2026-11-03 10:00', $utc);
        $today = new DateTimeImmutable('today', $utc);
        $next = new \DateTime("tomorrow");
        $soon = new DateTimeImmutable(datetime: '+1 day', timezone: $utc);
        $ten = new DateTimeImmutable('10:00', $utc);
        $bare = new DateTimeImmutable;
        $empty = new DateTimeImmutable();
        $read = Carbon::now();
        $day = \Carbon\CarbonImmutable::today($zone);
        $made = date_create_immutable('yesterday');
        $kept = new DateTimeImmutable('2026-11-03 10:00 +1 day', $utc);
        $stamped = new DateTimeImmutable('@1793692800');
        $handed = new DateTimeImmutable($text, $utc);
        $pinned = FixedClock::at('2026-11-02 06:00:00')->now();
        PHP;

    expect($reads)->toBe([])
        ->and(realClockReads($sample))->toBe([
            '2: SystemClock',
            '3: new DateTimeImmutable(\'now\')',
            '4: time()',
            '8: new DateTimeImmutable(\'today\')',
            '9: new \DateTime("tomorrow")',
            '10: new DateTimeImmutable(\'+1 day\')',
            '11: new DateTimeImmutable(\'10:00\')',
            '12: new DateTimeImmutable()',
            '13: new DateTimeImmutable()',
            '14: Carbon::now()',
            '15: \Carbon\CarbonImmutable::today()',
            '16: date_create_immutable(\'yesterday\')',
        ]);
});
