<?php

use App\Application\Dto\BookingItemSnapshot;
use App\Application\Dto\BookingSnapshot;
use App\Application\Dto\Customer;
use App\Domain\Enum\BookingStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Notifications\CustomerMailLines;
use App\Notifications\IcsBuilder;
use App\Site\SiteLanguage;
use Tests\Support\LangLines;

/*
 * The file that goes with the confirmation, and the one message whose readers are other people's
 * software. A body a person reads forgives a stray character; a calendar client refuses the
 * whole file for one, so the format's rules about line endings, line length and escaping are
 * asserted here rather than eyeballed.
 *
 * One event for the whole chain, because a customer keeps one appointment in their diary and the
 * gaps inside it are the salon's arrangement, not theirs. Identified by the booking's uuid, so a
 * second import of the same booking updates the entry a client already holds rather than adding
 * a second one beside it; that is what the revision is for, and a move raises it.
 *
 * Instants are UTC here and only here. Every message renders the tenant's clock; a calendar
 * file is read by a machine that will render it in whatever clock its owner keeps.
 *
 * An item with its staff member is a line of decision.php, in the booking's language the message
 * beside the file is written in, read through a translator over the lang directory;
 * CustomerMailKeysTest proves the language it is asked in. The summary arrives spelled, the line
 * of customer-mail.php the message beside the file reads in the booking's language,
 * CustomerMailLines::summary.
 */

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

/**
 * A booking of luna's, its items spelled as a list of [service, staff, implicit, start hour].
 * Every item runs one hour from the hour given, on 3 November 2026 in Athens, which is UTC+2.
 *
 * @param  list<array{string, string, bool, int}>  $items
 */
function icsBooking(array $items = [['Haircut', 'Elena Papadaki', false, 10]], string $uuid = '7b7f0e5a-0000-4000-8000-000000000001'): BookingSnapshot
{
    $zone = new DateTimeZone('Europe/Athens');
    $utc = new DateTimeZone('UTC');
    $snapshots = [];

    foreach ($items as $sort => [$service, $staff, $implicit, $hour]) {
        $start = (new DateTimeImmutable('2026-11-03 00:00', $zone))->add(new DateInterval(sprintf('PT%dH', $hour)));

        $snapshots[] = new BookingItemSnapshot(
            $sort,
            7 + $sort,
            $service,
            3 + $sort,
            $staff,
            $implicit,
            $start->setTimezone($utc),
            $start->add(new DateInterval('PT1H'))->setTimezone($utc),
            new Money(3500, Currency::of('EUR')),
        );
    }

    return new BookingSnapshot(
        $uuid,
        BookingStatus::Confirmed,
        null,
        null,
        new Customer('Casey Customer', 'casey@example.test', null),
        new Money(3500 * count($items), Currency::of('EUR')),
        $snapshots,
    );
}

function icsFile(BookingSnapshot $booking, string $tenant = 'Luna Hair Studio', string $stamp = '2026-10-01 07:00:00', int $revision = 0): string
{
    return IcsBuilder::file($booking, CustomerMailLines::summary($booking, $tenant, SiteLanguage::English), new DateTimeImmutable($stamp, new DateTimeZone('UTC')), $revision);
}

/**
 * The file as a reader unfolds it: every continuation line joined back onto the one before,
 * the way the format says a parser must before it looks at anything.
 *
 * @return list<string>
 */
function icsUnfolded(string $file): array
{
    $lines = [];

    foreach (explode("\r\n", rtrim($file, "\r\n")) as $line) {
        if ($lines !== [] && str_starts_with($line, ' ')) {
            $lines[count($lines) - 1] .= substr($line, 1);

            continue;
        }

        $lines[] = $line;
    }

    return $lines;
}

/**
 * The value of one property, unfolded and with the format's escapes read back, so that a test
 * asserts what a calendar client will show and not what the writer happened to spell.
 */
function icsValue(string $file, string $property): string
{
    foreach (icsUnfolded($file) as $line) {
        if (str_starts_with($line, $property.':')) {
            return str_replace(['\\n', '\\,', '\\;', '\\\\'], ["\n", ',', ';', '\\'], substr($line, strlen($property) + 1));
        }
    }

    throw new RuntimeException(sprintf('The file carries no %s.', $property));
}

/**
 * The value of one property exactly as it was written, escapes and all.
 */
function icsRaw(string $file, string $property): string
{
    foreach (icsUnfolded($file) as $line) {
        if (str_starts_with($line, $property.':')) {
            return substr($line, strlen($property) + 1);
        }
    }

    throw new RuntimeException(sprintf('The file carries no %s.', $property));
}

it('is one calendar holding one event, whatever the chain has in it', function () {
    $file = icsFile(icsBooking([['Colour', 'Elena Papadaki', false, 10], ['Haircut', 'Nikos Georgiou', false, 12]]));

    expect(substr_count($file, "BEGIN:VCALENDAR\r\n"))->toBe(1)
        ->and(substr_count($file, "END:VCALENDAR\r\n"))->toBe(1)
        ->and(substr_count($file, "BEGIN:VEVENT\r\n"))->toBe(1)
        ->and(substr_count($file, "END:VEVENT\r\n"))->toBe(1)
        ->and(icsUnfolded($file)[0])->toBe('BEGIN:VCALENDAR')
        ->and(icsValue($file, 'VERSION'))->toBe('2.0')
        ->and(icsRaw($file, 'PRODID'))->toContain('Kleise Radevou');
});

it('runs from the first item start to the last item end, in UTC, however many segments lie between', function () {
    $file = icsFile(icsBooking([['Colour', 'Elena Papadaki', false, 10], ['Haircut', 'Nikos Georgiou', false, 13]]));

    // Athens is UTC+2 on 3 November 2026: the chain starts at 10:00 local, 08:00 UTC, and its
    // last segment ends at 14:00 local, 12:00 UTC.
    expect(icsValue($file, 'DTSTART'))->toBe('20261103T080000Z')
        ->and(icsValue($file, 'DTEND'))->toBe('20261103T120000Z');
});

it('is identified by the booking uuid, so a client updates the entry it already holds', function () {
    expect(icsValue(icsFile(icsBooking()), 'UID'))->toBe('7b7f0e5a-0000-4000-8000-000000000001');
});

it('names the business and every service in its summary, the one line of customer-mail.php the message hands it', function () {
    $summary = icsValue(icsFile(icsBooking([['Colour', 'Elena Papadaki', false, 10], ['Haircut', 'Nikos Georgiou', false, 12]])), 'SUMMARY');

    expect($summary)->toBe(LangLines::line('customer-mail.calendar.summary', ['service' => 'Colour, Haircut', 'business' => 'Luna Hair Studio']));
});

it('writes the summary it is handed as it is handed, escaped, reading no translator for it', function () {
    $file = IcsBuilder::file(icsBooking(), 'Haircut; then, a coffee', new DateTimeImmutable('2026-10-01 07:00:00', new DateTimeZone('UTC')), 0);

    expect(icsValue($file, 'SUMMARY'))->toBe('Haircut; then, a coffee')
        ->and(icsRaw($file, 'SUMMARY'))->toBe('Haircut\\; then\\, a coffee');
});

it('names a staff member in the description only where the row is not the implicit one', function () {
    $named = icsValue(icsFile(icsBooking([['Haircut', 'Elena Papadaki', false, 10]])), 'DESCRIPTION');
    $implicit = icsValue(icsFile(icsBooking([['Initial assessment', 'Pier Physiotherapy', true, 10]])), 'DESCRIPTION');

    expect($named)->toBe(LangLines::line('decision.facts.item', ['service' => 'Haircut', 'staff' => 'Elena Papadaki']))
        ->and($implicit)->toBe('Initial assessment')
        ->and($implicit)->not->toContain('Pier Physiotherapy');
});

it('carries no manage link and no token anywhere, because a calendar entry gets forwarded', function () {
    $file = icsFile(icsBooking());

    expect($file)->not->toContain('token')
        ->and($file)->not->toContain('/manage/')
        ->and($file)->not->toContain('http');
});

it('escapes a name carrying a comma, a semicolon, a backslash or a line break', function () {
    $file = icsFile(icsBooking([["Cut, colour; the \\ lot\nand more", 'Elena Papadaki', false, 10]]));
    $raw = icsRaw($file, 'DESCRIPTION');

    expect(icsValue($file, 'DESCRIPTION'))->toContain("Cut, colour; the \\ lot\nand more")
        ->and($raw)->toContain('\\,')
        ->and($raw)->toContain('\\;')
        ->and($raw)->toContain('\\\\')
        ->and($raw)->toContain('\\n')
        // Nothing raw is left behind: an unescaped comma or semicolon ends the value as far as
        // the format is concerned, and a raw line break ends the line.
        ->and(preg_match('/(?<!\\\\)[,;]/', $raw))->toBe(0)
        ->and($raw)->not->toContain("\n");
});

it('folds a line past the format limit and unfolds back to what it said', function () {
    $name = str_repeat('Extremely thorough ', 12).'treatment';
    $file = icsFile(icsBooking([[$name, 'Elena Papadaki', false, 10]]));

    foreach (explode("\r\n", rtrim($file, "\r\n")) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }

    expect($file)->toContain("\r\n ")
        ->and(icsValue($file, 'DESCRIPTION'))->toContain($name)
        ->and(icsValue($file, 'SUMMARY'))->toContain($name);
});

it('folds a line of text that is not ASCII without cutting a character in half', function () {
    // A tenant's own words are not this repository's ASCII, whatever the templates are, and the
    // limit the format sets is in octets. A fold taken at the 75th octet of a three-octet
    // character hands a reader that does not unfold first two thirds of a letter, so it is
    // taken back to the start of one. The name is written here in escapes, so this file stays
    // ASCII while the value in it does not.
    $name = 'A'.str_repeat("\u{4e2d}", 40);
    $file = icsFile(icsBooking([[$name, 'Elena Papadaki', false, 10]]));
    $lines = explode("\r\n", rtrim($file, "\r\n"));

    foreach ($lines as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75)
            // Every line stands on its own as text: no continuation byte opens one and no
            // character is left unfinished at the end of one.
            ->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue();
    }

    // The back-off is proved by the encoding check in the loop above: a fold that cut blindly
    // at the 75th octet would leave a continuation byte opening one line and a character
    // unfinished at the end of the one before, and mb_check_encoding refuses both. The
    // shortest line being under the limit proves nothing, BEGIN:VCALENDAR is under it under
    // any fold, so no such assertion is made.
    expect($file)->toContain("\r\n ")
        ->and(icsValue($file, 'DESCRIPTION'))->toContain($name)
        ->and(icsValue($file, 'SUMMARY'))->toContain($name);
});

it('ends every line the way the format ends them, the last one included', function () {
    $file = icsFile(icsBooking());

    expect($file)->toEndWith("END:VCALENDAR\r\n")
        // No bare line feed anywhere: every one of them is preceded by a carriage return.
        ->and(preg_match('/(?<!\r)\n/', $file))->toBe(0);
});

it('is ASCII, like every template', function () {
    expect(preg_match('/\A[\x0d\x0a\x20-\x7e]+\z/', icsFile(icsBooking())))->toBe(1);
});

it('supersedes an earlier file for the same booking when it is built at a later revision', function () {
    $booking = icsBooking();
    $first = icsFile($booking, stamp: '2026-10-01 07:00:00', revision: 0);
    $second = icsFile($booking, stamp: '2026-10-02 07:00:00', revision: 1);

    // The two fields a client compares: the same entry, a higher revision, and a stamp that is
    // no older, which is what decides it where two files claim the same revision.
    expect(icsValue($second, 'UID'))->toBe(icsValue($first, 'UID'))
        ->and((int) icsValue($second, 'SEQUENCE'))->toBeGreaterThan((int) icsValue($first, 'SEQUENCE'))
        ->and(icsValue($first, 'SEQUENCE'))->toBe('0')
        ->and(icsValue($second, 'DTSTAMP'))->toBeGreaterThanOrEqual(icsValue($first, 'DTSTAMP'))
        ->and(icsValue($first, 'DTSTAMP'))->toBe('20261001T070000Z');
});

it('is attached under a name and a type a calendar client opens', function () {
    $attachment = IcsBuilder::attachment(icsBooking(), CustomerMailLines::summary(icsBooking(), 'Luna Hair Studio', SiteLanguage::English), new DateTimeImmutable('2026-10-01 07:00:00', new DateTimeZone('UTC')), 0);

    expect($attachment->name)->toBe('booking.ics')
        ->and($attachment->contentType)->toBe('text/calendar')
        ->and($attachment->contents)->toBe(icsFile(icsBooking()));
});
