<?php

declare(strict_types=1);

namespace App\Application\Payment;

use DateTimeImmutable;

/**
 * A tenant's connection to a provider as the store reads it: which provider, how it is
 * connected, where it stands and what the last stored check said.
 *
 * It carries no secret. The credentials of an own-mode connection are write-only, so the read
 * says that some are stored and never what they are.
 */
final readonly class PaymentConnection
{
    /**
     * @param  string  $provider  the registry slug
     * @param  string|null  $accountRef  platform mode: the provider's connected-account identifier
     * @param  array<array-key, mixed>|null  $requirements  what the provider last said is outstanding, verbatim
     * @param  bool|null  $lastCheckOk  the stored result of the last check, null where none has run
     * @param  bool  $hasCredentials  whether credentials are stored, which is all the read says of them
     */
    public function __construct(
        public string $provider,
        public ConnectionMode $mode,
        public ConnectionState $state,
        public ?string $accountRef,
        public ?array $requirements,
        public ?DateTimeImmutable $lastCheckedAt,
        public ?bool $lastCheckOk,
        public bool $hasCredentials,
    ) {}

    /**
     * Whether this connection takes money: active, and its stored check has not failed. A
     * connection nobody has checked yet works; one whose last check failed does not, until a
     * later check passes. Read from the row alone.
     */
    public function works(): bool
    {
        return self::working($this->state, $this->lastCheckOk);
    }

    /**
     * The rule works() reads, for a reader holding the row's two columns rather than the row:
     * the settings read, which says whether the tenant takes payments for the approved key's
     * lock and so asks exactly what the provider's choice asks.
     */
    public static function working(ConnectionState $state, ?bool $lastCheckOk): bool
    {
        return $state === ConnectionState::Active && $lastCheckOk !== false;
    }

    /**
     * Whether a connection made through us takes payments while its provider still lists
     * information it needs from the tenant: active, with what the provider listed stored, which
     * an account standing carries while taking payments only when something is listed. A row
     * switched off here is not taking payments, so it waits for Switch back on.
     */
    public function informationDue(): bool
    {
        return $this->mode === ConnectionMode::Platform && $this->state === ConnectionState::Active
            && $this->requirements !== null && $this->requirements !== [];
    }
}
