<?php

declare(strict_types=1);

namespace App\Platform;

use App\Site\SiteLanguage;
use Illuminate\Contracts\Translation\Translator;
use RuntimeException;

/**
 * A close refused: the reason as a machine code and the sentence to be shown verbatim, the admin
 * answering them as the envelope at 409 and the console as an error flash on the detail, the
 * retention sweep passing over the row. Nothing was written and nothing was sent.
 *
 * The two an owner meets, already closing and not open, hold keys of
 * lang/{locale}/admin/billing.php under refusals, read through sentence() in the request's language
 * for the owner and in English for the console, which names it in the call. The other three are the
 * console's and the sweep's alone and stay as they are.
 */
final class CloseRefused extends RuntimeException
{
    public const string ALREADY_CLOSING = 'admin/billing.refusals.already_closing';

    /**
     * A row its closer does not close: for the owner, one the operator suspended or the demo's
     * rebuilding, met under the lock.
     */
    public const string NOT_OPEN = 'admin/billing.refusals.not_open';

    /** The details held as keys, the owner's two. */
    private const array KEYED = [self::ALREADY_CLOSING, self::NOT_OPEN];

    /** A row still provisioning, which the console's close refuses. */
    public const string PROVISIONING = 'An account still being set up cannot be closed.';

    /**
     * A row the drop closed, which the console offers nothing on and no slug reaches; the writer
     * meets its kept closing_at first and answers already closing.
     */
    public const string CLOSED = 'A closed account cannot be closed again.';

    /**
     * A row the retention sweep's close of a lapsed subscriber read as due and the lock found no
     * longer so: a subscription landed, or the row moved, in between. The sweep passes over it;
     * nobody is shown the sentence.
     */
    public const string NOT_DUE = 'This account is no longer due to close.';

    private function __construct(public readonly string $reason, public readonly string $detail)
    {
        parent::__construct($detail);
    }

    /**
     * The sentence shown verbatim: an owner's two read in the language given, else the
     * application's locale, the request's (the console names English), and the console's own as
     * they stand.
     */
    public function sentence(?SiteLanguage $language = null): string
    {
        if (! in_array($this->detail, self::KEYED, true)) {
            return $this->detail;
        }

        $translator = app(Translator::class);
        $line = $translator->get($this->detail, [], $language->value ?? $translator->getLocale());

        return is_string($line) ? $line : $this->detail;
    }

    public static function alreadyClosing(): self
    {
        return new self('already_closing', self::ALREADY_CLOSING);
    }

    public static function notOpen(): self
    {
        return new self('not_closable', self::NOT_OPEN);
    }

    public static function provisioning(): self
    {
        return new self('not_closable', self::PROVISIONING);
    }

    public static function closed(): self
    {
        return new self('not_closable', self::CLOSED);
    }

    public static function notDue(): self
    {
        return new self('not_closable', self::NOT_DUE);
    }
}
