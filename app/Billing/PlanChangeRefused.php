<?php

declare(strict_types=1);

namespace App\Billing;

use App\Site\SiteLanguage;
use Illuminate\Contracts\Translation\Translator;
use NumberFormatter;
use RuntimeException;

/**
 * A plan change refused before the provider is asked, each constructor one case with its machine
 * code and the key of the sentence the Billing screen shows verbatim, read through sentence() in
 * the reader's language, ErrorEnvelope carrying both; one code names one reason. The downgrade
 * refused is removing staff while more than one active staff member exists, the implicit one
 * counted; it names the count and says where to go. The checkout's plan choice meets the same
 * threshold under the same code, worded for a plan being chosen rather than changed, and for
 * whether the owner can deactivate staff at all: inside a trial they can, and once setup changes
 * are paused they cannot, so the road there is a plan with Staff first. The rest refuse a change
 * the screen does not offer, since every action also refuses in its handler.
 */
final class PlanChangeRefused extends RuntimeException
{
    /** Removing a feature the active staff rows still need. */
    public const string DOWNGRADE = 'downgrade_refused';

    /**
     * The subscription takes no plan change in its present state: no subscriptions row, the
     * first charge still pending, or the subscription ended. One reason, each case worded.
     */
    public const string UNCHANGEABLE = 'plan_unchangeable';

    /** The subscription is on a price no catalogue plan carries, so there is no plan to change from. */
    public const string UNLISTED = 'plan_unlisted';

    /** The plan asked for is not one the catalogue holds: a request no screen sends. */
    public const string UNKNOWN = 'invalid_request';

    /**
     * The refusals' sentences, keys of lang/{locale}/admin/billing.php under refusals: the
     * floor's three read as choices by the active members, :count the count and :plan the
     * plan's name, the rest one line each.
     */
    public const string DOWNGRADE_SENTENCE = 'admin/billing.refusals.downgrade';

    public const string UNCHOOSABLE_SENTENCE = 'admin/billing.refusals.unchoosable';

    public const string UNCHOOSABLE_PAUSED_SENTENCE = 'admin/billing.refusals.unchoosable_paused';

    public const string NO_SUBSCRIPTION_SENTENCE = 'admin/billing.refusals.no_subscription';

    public const string CHARGE_PENDING_SENTENCE = 'admin/billing.refusals.charge_pending';

    public const string ENDED_SENTENCE = 'admin/billing.refusals.ended';

    public const string UNLISTED_SENTENCE = 'admin/billing.refusals.unlisted';

    public const string UNKNOWN_PLAN_SENTENCE = 'admin/billing.refusals.unknown_plan';

    /**
     * @param  string  $key  the sentence's key, also the exception's message
     * @param  int|null  $count  the active members a floor refusal is chosen by, else null
     * @param  array<string, string>  $values  the sentence's other placeholders
     */
    private function __construct(public readonly string $reason, public readonly string $key, public readonly ?int $count = null, public readonly array $values = [])
    {
        parent::__construct($key);
    }

    public static function downgrade(int $active): self
    {
        return new self(self::DOWNGRADE, self::DOWNGRADE_SENTENCE, $active);
    }

    /**
     * A plan without staff chosen at the checkout while more than one staff member is active:
     * the plan by its name, and whether setup changes are paused, which takes deactivating staff
     * with them.
     */
    public static function unchoosable(string $plan, int $active, bool $paused): self
    {
        return new self(self::DOWNGRADE, $paused ? self::UNCHOOSABLE_PAUSED_SENTENCE : self::UNCHOOSABLE_SENTENCE, $active, ['plan' => $plan]);
    }

    public static function noSubscription(): self
    {
        return new self(self::UNCHANGEABLE, self::NO_SUBSCRIPTION_SENTENCE);
    }

    public static function chargePending(): self
    {
        return new self(self::UNCHANGEABLE, self::CHARGE_PENDING_SENTENCE);
    }

    public static function ended(): self
    {
        return new self(self::UNCHANGEABLE, self::ENDED_SENTENCE);
    }

    public static function unlisted(): self
    {
        return new self(self::UNLISTED, self::UNLISTED_SENTENCE);
    }

    public static function unknownPlan(): self
    {
        return new self(self::UNKNOWN, self::UNKNOWN_PLAN_SENTENCE);
    }

    /**
     * The sentence the screen shows verbatim, in the language given, else the application's
     * locale, the request's: the owner's under /admin, English on the console. A floor refusal is
     * chosen by its count, spelled in digits as the language groups them.
     */
    public function sentence(?SiteLanguage $language = null): string
    {
        $translator = app(Translator::class);
        $locale = $language->value ?? $translator->getLocale();

        if ($this->count === null) {
            $line = $translator->get($this->key, $this->values, $locale);

            return is_string($line) ? $line : $this->key;
        }

        $spelled = (new NumberFormatter($locale, NumberFormatter::DECIMAL))->format($this->count);

        return $translator->choice($this->key, $this->count, [...$this->values, 'count' => is_string($spelled) ? $spelled : (string) $this->count], $locale);
    }
}
