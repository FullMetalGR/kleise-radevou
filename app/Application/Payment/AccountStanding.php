<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * Where a platform connection stands by the provider's own word, read from an account update on
 * the platform's webhook: taking payments, onboarding unfinished, or wanting something from the
 * tenant. What the provider said is outstanding rides with the last two, verbatim, for the
 * Payments section to show. An account taking payments carries it only while the provider still
 * lists information it needs, which the section states in one line; one that needs nothing
 * carries null.
 */
final readonly class AccountStanding
{
    /**
     * @param  array<array-key, mixed>|null  $requirements
     */
    private function __construct(
        public ConnectionState $state,
        public ?array $requirements,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $outstanding  what the provider still lists as needed, verbatim, or null for nothing
     */
    public static function takingPayments(?array $outstanding): self
    {
        return new self(ConnectionState::Active, $outstanding === [] ? null : $outstanding);
    }

    /**
     * @param  array<array-key, mixed>  $requirements
     */
    public static function unfinished(array $requirements): self
    {
        return new self(ConnectionState::Pending, $requirements);
    }

    /**
     * @param  array<array-key, mixed>  $requirements
     */
    public static function wanting(array $requirements): self
    {
        return new self(ConnectionState::Restricted, $requirements);
    }
}
