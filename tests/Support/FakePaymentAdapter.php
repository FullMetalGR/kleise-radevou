<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Payment\AccountStanding;
use App\Application\Payment\ConnectionCheck;
use App\Application\Payment\ConnectionMode;
use App\Application\Payment\CredentialField;
use App\Application\Payment\Credentials;
use App\Application\Payment\FeeMechanism;
use App\Application\Payment\NotificationRefused;
use App\Application\Payment\OnboardingEntry;
use App\Application\Payment\PaymentAdapter;
use App\Application\Payment\PaymentConnection;
use App\Application\Payment\PaymentConnections;
use App\Application\Payment\PaymentNotice;
use App\Application\Payment\PaymentProvider;
use App\Application\Payment\PlatformNotice;
use App\Application\Payment\ProviderDescriptor;
use App\Application\Payment\WebhookSignature;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Closure;
use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/**
 * The payment provider every gate meets, and the second provider that proves the registry: a
 * descriptor of its own beside Stripe's, both modes declared, three credential fields where
 * Stripe has two and none of them named as Stripe's are.
 *
 * It opens no connection to anywhere. What it was asked is kept here, on the one adapter the
 * registry holds, so a test reads it back whichever provider instance took the question,
 * FakePaymentProvider or, under answersNowhere, NowherePaymentProvider. It is the tenants'
 * payments' fake and shares nothing with the platform's billing fake.
 */
final class FakePaymentAdapter implements PaymentAdapter
{
    public const string SLUG = 'paydesk';

    /** The type of the notice saying a payment for a booking succeeded. */
    public const string SETTLED = 'payment.settled';

    /** The type of a platform notice saying where a connected account stands. */
    public const string ACCOUNT = 'account.standing';

    /** The platform's keys a test sets for the fake under payments.platform.paydesk. */
    public const array PLATFORM_KEYS = ['api_token' => 'pdk_platform_token_51d0', 'signing_phrase' => 'platform paydesk phrase'];

    /** @var list<string> the connected accounts opened, by reference */
    public array $accounts = [];

    /** @var list<array{account: string, return: string, token: string}> the onboarding links handed out */
    public array $links = [];

    /** A message the next account opening throws with. */
    public ?string $openFailsWith = null;

    /** A message the next onboarding link throws with. */
    public ?string $linkFailsWith = null;

    /**
     * Every notification this adapter was asked to verify, in order.
     *
     * @var list<array{transactionOpen: bool|null}>
     */
    public array $verified = [];

    /**
     * What the descriptor answers for canExpireCheckout: declared present by default, and a
     * test may declare it absent with false or leave it undeclared with null.
     */
    public ?bool $canExpireCheckout = true;

    /**
     * What the descriptor answers for supportsRefundFlag: declared by default, and a test may
     * declare that no note can be left with false, so no flag reaches the provider.
     */
    public bool $supportsRefundFlag = true;

    /**
     * Each question as [operation, booking uuid], then its ref and its note where it carries them.
     *
     * @var list<list<string>>
     */
    public array $asked = [];

    /**
     * Every checkout session the fake opened, by its id: the booking, the page, the end asked
     * for and the end given, the total asked, the return address and how many sessions the
     * request said moves had cleared before it.
     *
     * @var array<string, array{uuid: string, url: string, askedEnd: DateTimeImmutable, endsAt: DateTimeImmutable, total: Money, returnUrl: string, sessionsCleared: int}>
     */
    public array $sessions = [];

    /**
     * Whether a database transaction was open at each question that would leave the process (a
     * checkout, a flag, an expiry), null where none is booted.
     *
     * @var list<bool|null>
     */
    public array $transactionOpen = [];

    /** @var list<string> the sessions expired, by id */
    public array $expired = [];

    /**
     * The fake's session lifetime: a session ends this many seconds after the end it was asked
     * for, 900 by default, a session outliving its hold as a provider's shortest session makes
     * one do (Stripe's ends 1800 seconds after the hold, its thirty-minute floor). Negative ends
     * it before the hold. Measured from the end asked, an instant the database wrote, and never
     * from a clock. A session after a move cleared the booking's session ends with the hold as
     * asked and reads this not at all, as the port's contract has it (CheckoutRequest).
     */
    public int $sessionOverrunSeconds = 900;

    /** A message the next checkout question throws with, as a provider that fails would. */
    public ?string $failWith = null;

    /**
     * The sessions the customer completed at the fake, by id, before any notification landed: a
     * question naming one answers that its payment is on its way (SessionWithheld::Completed)
     * and opens nothing, as Stripe answers a recorded session it has completed.
     *
     * @var list<string>
     */
    public array $completed = [];

    /**
     * Whether a later session, one asked for after a move cleared the booking's session
     * (sessionsCleared above zero), is refused as too short for the hold, as Stripe's floor
     * refuses one on a hold with less than thirty-one minutes left: answered
     * SessionWithheld::TooShort and nothing opened. The fake has no clock to measure the hold
     * against, so a case says when.
     */
    public bool $laterSessionTooShort = false;

    /**
     * A message every flag throws with after it is recorded, as a provider that fails would; the
     * checkout questions are untouched by it.
     */
    public ?string $flagFailsWith = null;

    /**
     * A message every expiry throws with after it is recorded, as a provider that fails would;
     * the session it names is not added to expired. The checkout questions and the flag are
     * untouched by it, and NowherePaymentProvider does not read it.
     */
    public ?string $expireFailsWith = null;

    /**
     * Whether the provider handed out answers isAvailable false while the connection works, so
     * the settlement question's answer is on site with the connection's row active and checked:
     * the one state in which an on-site settlement's expiry reaches the provider through the
     * tenant's, which answers as the null provider for a connection that does not work.
     * FakePaymentProvider reads it; NowherePaymentProvider does not and stands available.
     */
    public bool $unavailable = false;

    /**
     * Whether the provider handed out stands available and answers every checkout question with
     * no session, NowherePaymentProvider. Each question is still recorded here, in asked and
     * transactionOpen, so a case pins it asked once and opened nothing. It leaves five switches
     * inert, since NowherePaymentProvider reads none of them: failWith and expireFailsWith (no
     * question throws), whileAsked (nothing runs while a question is answered),
     * sessionOverrunSeconds (no session's end is computed) and unavailable (it stands
     * available); canExpireCheckout still answers through the descriptor.
     */
    public bool $answersNowhere = false;

    /** The reason the next connection test fails with, or null where it passes. */
    public ?string $checkFailsWith = null;

    /**
     * What every connection test throws, against its contract, before it answers; null where it
     * answers as checkFailsWith says.
     */
    public ?Throwable $checkThrows = null;

    /**
     * What every connection test answers whole, a reason's key and its values among it; null
     * where checkFailsWith decides.
     */
    public ?ConnectionCheck $checkAnswers = null;

    /** @var (Closure(): mixed)|null run while a connection test is being answered */
    public ?Closure $whileChecked = null;

    /** @var (Closure(): mixed)|null run while a checkout question is being answered */
    public ?Closure $whileAsked = null;

    public function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            slug: self::SLUG,
            name: 'Paydesk',
            modes: [ConnectionMode::Platform, ConnectionMode::Own],
            credentialFields: [
                new CredentialField('merchant_code', 'Merchant code', 'The code Paydesk gave your business.', false),
                new CredentialField('api_token', 'API token', 'The token Paydesk calls are made with.', true),
                new CredentialField('signing_phrase', 'Signing phrase', 'The phrase Paydesk signs its notifications with.', true),
            ],
            onboardingEntry: OnboardingEntry::HostedLink,
            feeMechanism: FeeMechanism::Parameter,
            webhookSignature: WebhookSignature::signedHeader('Paydesk-Seal'),
            supportsRefundFlag: $this->supportsRefundFlag,
            currencies: ['EUR', 'USD', 'JPY'],
            rateUrl: 'https://paydesk.test/pricing',
            canExpireCheckout: $this->canExpireCheckout,
        );
    }

    public function provider(PaymentConnection $connection, PaymentConnections $connections): PaymentProvider
    {
        return $this->answersNowhere ? new NowherePaymentProvider($this) : new FakePaymentProvider($this);
    }

    /**
     * Records a question a provider this adapter handed out was asked: the operation, the
     * booking and its ref and note, and for a question that would leave the process whether a
     * transaction was open.
     */
    public function record(string $operation, string $uuid, string ...$details): void
    {
        $this->asked[] = [$operation, $uuid, ...array_values($details)];

        if (in_array($operation, ['checkoutUrl', 'flagPayment', 'expireCheckout'], true)) {
            $this->transactionOpen[] = self::transactionOpen();
        }
    }

    /**
     * The connection test: passes unless checkFailsWith names a reason, throws what checkThrows
     * holds, answers checkAnswers where it is set, runs whileChecked first, and reads no
     * credential.
     */
    public function check(Credentials $credentials): ConnectionCheck
    {
        if ($this->whileChecked !== null) {
            ($this->whileChecked)();
        }

        if ($this->checkThrows !== null) {
            throw $this->checkThrows;
        }

        if ($this->checkAnswers !== null) {
            return $this->checkAnswers;
        }

        return $this->checkFailsWith === null ? ConnectionCheck::passed() : ConnectionCheck::failed($this->checkFailsWith);
    }

    /**
     * How many sessions the fake has created, whoever asked.
     */
    public function created(): int
    {
        return count($this->sessions);
    }

    /**
     * Paydesk's own scheme, declared by the descriptor's header: the seal is the hex HMAC-SHA256
     * of the raw body keyed by the connection's signing phrase. A body it vouches for is a JSON
     * object with an id and a type; payment.settled names the booking and the payment, and the
     * amount paid where its body carries one, as {minor, currency}. Each verification is
     * recorded, with whether a transaction was open when it was asked.
     */
    public function verify(string $body, string $signature, Credentials $credentials): PaymentNotice
    {
        $this->verified[] = ['transactionOpen' => self::transactionOpen()];
        $phrase = $credentials->field('signing_phrase');

        if ($phrase === '' || ! hash_equals(self::seal($body, $phrase), $signature)) {
            throw NotificationRefused::because('The Paydesk seal does not match the body.');
        }

        $notice = json_decode($body, true);

        if (! is_array($notice) || ! is_string($notice['id'] ?? null) || ! is_string($notice['type'] ?? null)) {
            throw NotificationRefused::because('The Paydesk body is not a notification.');
        }

        $booking = $notice['booking'] ?? null;
        $payment = $notice['payment'] ?? null;
        $amount = $notice['amount'] ?? null;
        $minor = is_array($amount) ? ($amount['minor'] ?? null) : null;
        $currency = is_array($amount) ? ($amount['currency'] ?? null) : null;

        return new PaymentNotice(
            $notice['id'],
            $notice['type'],
            $notice['type'] === self::SETTLED,
            is_string($booking) ? $booking : null,
            is_string($payment) ? $payment : null,
            is_int($minor) && is_string($currency) ? new Money($minor, Currency::of($currency)) : null,
        );
    }

    /**
     * The seal Paydesk puts on a body for a connection holding this signing phrase.
     */
    public static function seal(string $body, string $phrase): string
    {
        return hash_hmac('sha256', $body, $phrase);
    }

    /**
     * Whether the default connection had a transaction open, or null where no database is
     * booted, as in a unit test.
     */
    private static function transactionOpen(): ?bool
    {
        $container = Container::getInstance();

        if (! $container->bound('db')) {
            return null;
        }

        return $container->make(DatabaseManager::class)->connection()->transactionLevel() > 0;
    }

    public function openAccount(Credentials $platform): string
    {
        if ($this->openFailsWith !== null) {
            throw new RuntimeException($this->openFailsWith);
        }
        $this->accounts[] = $account = 'pdk_acct_'.(count($this->accounts) + 1);

        return $account;
    }

    public function onboardingLink(Credentials $platform, string $accountRef, string $returnUrl): string
    {
        if ($this->linkFailsWith !== null) {
            throw new RuntimeException($this->linkFailsWith);
        }
        $this->links[] = ['account' => $accountRef, 'return' => $returnUrl, 'token' => $platform->field('api_token')];

        return 'https://onboarding.paydesk.test/'.$accountRef.'/'.count($this->links);
    }

    /**
     * The platform's seal is the own mode's, keyed by the platform's signing phrase. An
     * account.standing notice names its account and says "taking", "unfinished" or "wanting",
     * with what is outstanding under "outstanding", which an account taking payments carries
     * only while it lists something.
     */
    public function verifyPlatform(string $body, string $signature, Credentials $platform): PlatformNotice
    {
        $phrase = $platform->field('signing_phrase');
        if ($phrase === '' || ! hash_equals(self::seal($body, $phrase), $signature)) {
            throw NotificationRefused::because('The Paydesk seal does not match the body.');
        }
        $notice = json_decode($body, true);
        if (! is_array($notice) || ! is_string($notice['id'] ?? null) || ! is_string($notice['type'] ?? null)) {
            throw NotificationRefused::because('The Paydesk body is not a notification.');
        }
        $account = $notice['account'] ?? null;
        $outstanding = $notice['outstanding'] ?? [];
        $outstanding = is_array($outstanding) ? $outstanding : [];
        $standing = $notice['type'] !== self::ACCOUNT ? null : match ($notice['standing'] ?? null) {
            'taking' => AccountStanding::takingPayments($outstanding),
            'unfinished' => AccountStanding::unfinished($outstanding),
            default => AccountStanding::wanting($outstanding),
        };

        return new PlatformNotice($notice['id'], $notice['type'], is_string($account) ? $account : null, $standing);
    }
}
