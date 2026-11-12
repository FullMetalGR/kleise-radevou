<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;

/**
 * The checkout refused, a 409, for one of two reasons. resubscribe_refused: the tenant's
 * subscription has not ended, or no subscriptions row names a customer to start a checkout for;
 * one sentence for every case, since the Billing screen offers the control only where the
 * checkout serves and a press reaching this refusal is one the screen never sends.
 * window_ending: the account is closing and its window ends before a checkout opened now would
 * lapse, so a card could be charged after the account is gone with nothing left to record it.
 */
final class ResubscribeRefused extends RuntimeException
{
    public const string CODE = 'resubscribe_refused';

    /** The code of a closing window that ends before a checkout's lifetime would. */
    public const string WINDOW_ENDING = 'window_ending';

    /** The sentence's key in lang/{locale}/admin/billing.php. */
    public const string SENTENCE = 'admin/billing.refusals.resubscribe';

    /** The closing window's sentence's key in lang/{locale}/admin/billing.php. */
    public const string WINDOW_ENDING_SENTENCE = 'admin/billing.refusals.window_ending';

    /**
     * @param  string  $reason  the wire's code
     * @param  string  $key  the sentence's key, also the exception's message
     */
    public function __construct(public readonly string $reason = self::CODE, public readonly string $key = self::SENTENCE)
    {
        parent::__construct($key);
    }

    /** A closing account whose window ends before a checkout opened now would lapse. */
    public static function windowEnding(): self
    {
        return new self(self::WINDOW_ENDING, self::WINDOW_ENDING_SENTENCE);
    }
}
