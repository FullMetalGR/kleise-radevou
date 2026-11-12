<?php

declare(strict_types=1);

namespace App\Site;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Translation\Translator;
use IntlDateFormatter;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * The version of the terms of service a signup accepts: the day the English terms file's version
 * line names, read here and nowhere else, by parsing the line with the form it is written in,
 * ICU's day, month name and year. The Greek file's line is the same day as the formatter writes
 * it in Greek, which TermsVersionTest pins, so the two lines and the day a row stores cannot
 * drift apart. A line this cannot read whole is refused with a sentence naming the file, never
 * stored as a guess.
 */
final readonly class TermsVersion
{
    /** The terms' file under lang/{locale}, and the prefix of its version key. */
    public const string FILE = 'legal/terms';

    /** The form both version lines are written in, ICU's pattern. */
    public const string PATTERN = 'd MMMM y';

    /** How a day crosses to the date column and back. */
    public const string DAY = 'Y-m-d';

    public function __construct(private Translator $translator) {}

    /**
     * The day the English terms file names, as Y-m-d.
     *
     * @throws UnexpectedValueException when the line cannot be read
     */
    public function day(): string
    {
        $line = $this->translator->get(self::FILE.'.version', [], SiteLanguage::English->value);

        return self::parse(is_string($line) ? $line : '');
    }

    /**
     * The day an English version line names, as Y-m-d, the whole line read or nothing. ICU's parse
     * position counts UTF-16 units and strlen counts bytes, which agree only because the English
     * line is ASCII, as every file outside lang/el is.
     *
     * @throws UnexpectedValueException when the line cannot be read
     */
    public static function parse(string $line): string
    {
        $formatter = self::formatter(SiteLanguage::English);
        $formatter->setLenient(false);
        $position = 0;
        $timestamp = $formatter->parse($line, $position);

        if ($timestamp === false || $position !== strlen($line)) {
            throw new UnexpectedValueException(sprintf('The version line of lang/en/%s.php, "%s", names no day in the form "%s".', self::FILE, $line, self::PATTERN));
        }

        return (new DateTimeImmutable('@'.(int) $timestamp, new DateTimeZone('UTC')))->format(self::DAY);
    }

    /**
     * A Y-m-d day as a language's version line spells it.
     *
     * @throws InvalidArgumentException when the day is not a stored day
     */
    public static function spelled(string $day, SiteLanguage $language): string
    {
        $spelled = self::formatter($language)->format(self::dayFromStored($day));

        return is_string($spelled) ? $spelled : throw new UnexpectedValueException(sprintf('ICU could not spell the day %s.', $day));
    }

    /**
     * The day a Y-m-d string names, midnight UTC, read strictly: a day the calendar does not have,
     * 30 February, is refused rather than rolled into the next month.
     *
     * @throws InvalidArgumentException when the string is no such day
     */
    public static function dayFromStored(string $day): DateTimeImmutable
    {
        $instant = DateTimeImmutable::createFromFormat('!'.self::DAY, $day, new DateTimeZone('UTC'));

        if ($instant === false || $instant->format(self::DAY) !== $day) {
            throw new InvalidArgumentException(sprintf('"%s" is no day in the form %s.', $day, self::DAY));
        }

        return $instant;
    }

    private static function formatter(SiteLanguage $language): IntlDateFormatter
    {
        return new IntlDateFormatter($language->value, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, self::PATTERN);
    }
}
