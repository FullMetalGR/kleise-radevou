<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\AdminLines;
use Illuminate\Contracts\Translation\Translator;

/**
 * The home of the sentence the four image uploads of the admin share, the logo's, the cover's, a
 * gallery photo's and a staff photo's: a body with no file in it is refused with MISSING whichever
 * image it was for. This holds that sentence and the two readers its subclasses word their own
 * with; each subclass names its own field, size, rules and messages, and the keys of its TYPE and
 * SIZE sentences, the four type sentences in one shape in the file, the subject and then the three
 * types.
 *
 * Every sentence is a key of lang/{en,el}/admin/shell.php under validation.upload, read in the
 * request's language, the user's. A size is the code's own figure, MAX_KILOBYTES, never spelled in
 * the file: the line is read as a choice by its megabytes, :n the figure spelled in words by ICU
 * in the language the line is read in, the request's where the file holds the line in it, else the
 * translator's fallback, so a pending Greek file never puts a Greek word into its English
 * sentence.
 */
abstract class ImageUploadRequest extends AdminWriteRequest
{
    public const string MISSING = 'admin/shell.validation.upload.missing';

    /** The kilobytes in one megabyte, as the sizes are counted. */
    private const int KILOBYTES_A_MEGABYTE = 1024;

    /**
     * One line of the uploads' sentences in the request's language: the admin's one reader,
     * AdminLines.
     */
    protected function line(string $key): string
    {
        return AdminLines::line($key);
    }

    /**
     * A size sentence: the line chosen by the megabytes the kilobytes given make, the figure
     * spelled in words as :n by AdminLines::spelled().
     */
    protected function sizeLine(string $key, int $kilobytes): string
    {
        $megabytes = self::megabytesOf($kilobytes);

        return $this->container->make(Translator::class)->choice($key, $megabytes, ['n' => AdminLines::spelled($key, $megabytes)]);
    }

    /** The whole megabytes a size in kilobytes makes. */
    public static function megabytesOf(int $kilobytes): int
    {
        return intdiv($kilobytes, self::KILOBYTES_A_MEGABYTE);
    }
}
