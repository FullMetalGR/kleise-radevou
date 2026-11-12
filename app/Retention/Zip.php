<?php

declare(strict_types=1);

namespace App\Retention;

use Closure;
use DateTimeImmutable;
use LengthException;

/**
 * The zip format written straight to the response: the tenant's archive leaves the server as it
 * is written and is never a file on it, not even a temporary one, so a process killed halfway
 * through leaves nothing of a customer's data behind. PHP's ZipArchive writes to a path and no
 * streaming package is installed, which is why the format is written here: a local header and
 * the bytes for each entry, then the central directory naming every entry once and the end
 * record pointing at it (the format's specification, APPNOTE.TXT, sections 4.3.7, 4.3.12 and
 * 4.3.16).
 *
 * Each entry is deflated where deflating makes it smaller and stored otherwise, so the JSON
 * shrinks and an image, already compressed, goes in as it is. Every entry carries one modified
 * time, the export's instant on the tenant's clock, since a zip's time is a wall clock with no
 * zone. The archive is small: an entry, the archive and the entry count stay inside the plain
 * format's 32-bit and 16-bit fields, and one that would not is refused before a byte of it is
 * written, since no ZIP64 is written here.
 */
final readonly class Zip
{
    private const int LOCAL = 0x04034B50;

    private const int CENTRAL = 0x02014B50;

    private const int END = 0x06054B50;

    /** The version a reader needs for a deflated or stored entry, 2.0. */
    private const int VERSION = 20;

    private const int STORED = 0;

    private const int DEFLATED = 8;

    /** A local header's fixed part, before the entry's name. */
    private const int LOCAL_HEADER = 30;

    private const int MAX_SIZE = 0xFFFFFFFF;

    private const int MAX_ENTRIES = 0xFFFF;

    /**
     * Writes the archive of the entries in their order through the writer, each entry named by
     * its key.
     *
     * @param  array<string, string>  $entries  the bytes of each entry, keyed by its name in the archive
     * @param  Closure(string): void  $write
     *
     * @throws LengthException where an entry or the archive is past the plain format's fields
     */
    public static function stream(array $entries, DateTimeImmutable $modified, Closure $write): void
    {
        if (count($entries) > self::MAX_ENTRIES) {
            throw new LengthException('The archive holds more entries than a zip without ZIP64 can name.');
        }

        // The entries stored whole bound the archive from above, since an entry is deflated only
        // where that makes it smaller: within the fields, every offset below is too.
        $bound = 0;

        foreach ($entries as $name => $contents) {
            $bound += self::LOCAL_HEADER + strlen($name) + strlen($contents);
        }

        if ($bound > self::MAX_SIZE) {
            throw new LengthException('The archive is larger than a zip without ZIP64 can hold.');
        }

        [$time, $date] = self::dos($modified);
        $offset = 0;
        $directory = '';

        foreach ($entries as $name => $contents) {
            $deflated = gzdeflate($contents);
            [$method, $data] = $deflated !== false && strlen($deflated) < strlen($contents) ? [self::DEFLATED, $deflated] : [self::STORED, $contents];
            $crc = crc32($contents);
            $size = strlen($contents);
            $packed = strlen($data);
            $header = pack('VvvvvvVVVvv', self::LOCAL, self::VERSION, 0, $method, $time, $date, $crc, $packed, $size, strlen($name), 0).$name;
            $directory .= pack('VvvvvvvVVVvvvvvVV', self::CENTRAL, self::VERSION, self::VERSION, 0, $method, $time, $date, $crc, $packed, $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;

            $write($header);
            $write($data);
            $offset += strlen($header) + $packed;
        }

        $write($directory);
        $write(pack('VvvvvVVv', self::END, 0, 0, count($entries), count($entries), strlen($directory), $offset, 0));
    }

    /**
     * The instant as the format's two 16-bit fields, the time to the even second and the date
     * counted from 1980, as the wall clock it is given on reads them.
     *
     * @return array{int, int}
     */
    private static function dos(DateTimeImmutable $at): array
    {
        $hour = (int) $at->format('G');
        $minute = (int) $at->format('i');
        $second = (int) $at->format('s');
        $year = max(1980, (int) $at->format('Y'));

        return [
            ($hour << 11) | ($minute << 5) | intdiv($second, 2),
            (($year - 1980) << 9) | ((int) $at->format('n') << 5) | (int) $at->format('j'),
        ];
    }
}
