<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use InvalidArgumentException;
use Stringable;

/**
 * Who acted, in the five shapes of audit_log.actor: the guest through the booking page or the
 * magic link, the engine itself, a signed-in user by id, a queued job by name, and the
 * platform's operator acting in a support session by the operator's id.
 *
 * It is a value rather than an enum because three of the five carry something: a user and a
 * support operator carry an id and a job carries a name. A use case names the actor through
 * here, so no caller spells the column, and nothing that is not one of these five can be built
 * in the first place. The three patterns are the ones the actor CHECK is written from, and a
 * test holds the constraint and this class to each other by what each accepts rather than by
 * the text of the pattern: one string read by two engines is where a shared pattern drifts
 * without changing.
 *
 * A person's name and a person's address are not actors and never become one: privacy is a
 * design constraint, and the audit trail has no erasure step precisely because nothing
 * personal can reach it. A user and an operator are referenced by id for the same reason the
 * payload references the booking rather than copying it.
 */
final readonly class Actor implements Stringable
{
    public const string CUSTOMER = 'customer';

    public const string SYSTEM = 'system';

    /** What a user actor is spelled with, so the prefix is written once rather than beside every use of it. */
    private const string USER = 'user:';

    /** The shape of a user actor, as both a PCRE body and a POSIX regex the CHECK spells. */
    public const string USER_SHAPE = '^user:[0-9]+$';

    /** The shape of a job actor: a bare lowercase name, no address and no capital in it. */
    public const string JOB_SHAPE = '^job:[a-z][a-z0-9_-]*$';

    /** What a support actor is spelled with, the operator's id after it. */
    private const string SUPPORT = 'support:';

    /**
     * The shape of a support actor: the console's operator acting in a support session, named by
     * the operator's id in the central operators table and never by name or address.
     */
    public const string SUPPORT_SHAPE = '^support:[0-9]+$';

    /**
     * The modifier that makes the PCRE dollar mean what the POSIX one means. Without it a
     * trailing newline is allowed before the anchor, so "user:1\n" passes here and the CHECK
     * refuses it, and the class and the constraint accept different sets while their two
     * patterns read identically.
     */
    private const string ANCHORED = 'D';

    private function __construct(public string $value) {}

    /**
     * The guest who booked, cancelled or rescheduled through the booking page or the magic
     * link. Guests have no accounts, so there is nothing to reference and nothing to store.
     */
    public static function customer(): self
    {
        return new self(self::CUSTOMER);
    }

    /**
     * The engine acting on its own: the reap of the write protocol's step 4, and anything else
     * that happens because time passed rather than because somebody asked.
     */
    public static function system(): self
    {
        return new self(self::SYSTEM);
    }

    public static function user(int $id): self
    {
        if ($id < 1) {
            throw new InvalidArgumentException(sprintf('A user actor carries a user id; %d is not one.', $id));
        }

        return new self(self::USER.$id);
    }

    /**
     * The platform's operator acting on this tenant's bookings in a support session. The id
     * references the central operators table, as a user's references users; the tenant's trail
     * names the operator through it and stores nothing else.
     */
    public static function support(int $operatorId): self
    {
        if ($operatorId < 1) {
            throw new InvalidArgumentException(sprintf('A support actor carries an operator id; %d is not one.', $operatorId));
        }

        return new self(self::SUPPORT.$operatorId);
    }

    public static function job(string $name): self
    {
        if (preg_match('/'.self::JOB_SHAPE.'/'.self::ANCHORED, 'job:'.$name) !== 1) {
            throw new InvalidArgumentException(sprintf('A job actor is named in bare lowercase; "%s" is not.', $name));
        }

        return new self('job:'.$name);
    }

    /**
     * An actor read back from a stored row. The same five shapes the CHECK allows, refused here
     * as well so a row written before the constraint existed cannot become an actor either.
     */
    public static function of(string $value): self
    {
        $known = $value === self::CUSTOMER
            || $value === self::SYSTEM
            || preg_match('/'.self::USER_SHAPE.'/'.self::ANCHORED, $value) === 1
            || preg_match('/'.self::JOB_SHAPE.'/'.self::ANCHORED, $value) === 1
            || preg_match('/'.self::SUPPORT_SHAPE.'/'.self::ANCHORED, $value) === 1;

        if (! $known) {
            throw new InvalidArgumentException(sprintf('"%s" is not one of the five actor shapes.', $value));
        }

        return new self($value);
    }

    /**
     * Whether this is the guest acting on their own booking. It is the one distinction the
     * cancellation policy turns on: a customer is bound by the per-service windows and the
     * business, whether that is a signed-in user, support acting for it or the engine itself, is
     * bound by the status rule alone.
     */
    public function isCustomer(): bool
    {
        return $this->value === self::CUSTOMER;
    }

    /**
     * The id this actor references, where it is a signed-in user, and null for the other four
     * shapes. A column that records which user did something is written from here rather than
     * from a second argument beside the actor: two ways of saying who acted is one way for the
     * trail and the row to disagree, and the sweeper granting a timed-out request has nobody to
     * name at all, which this answers as the null it is rather than as a missing argument. A
     * support operator is no user of the tenant, so approved_by stays null for one as it does
     * for the sweeper.
     */
    public function userId(): ?int
    {
        return self::idAfter(self::USER_SHAPE, self::USER, $this->value);
    }

    /**
     * The operator this actor references, where it is support acting in a session, and null for
     * the other four shapes: the id the trail resolves the operator's name from.
     */
    public function supportOperatorId(): ?int
    {
        return self::idAfter(self::SUPPORT_SHAPE, self::SUPPORT, $this->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /** The digits after the prefix where the value has the shape, and null where it has not. */
    private static function idAfter(string $shape, string $prefix, string $value): ?int
    {
        if (preg_match('/'.$shape.'/'.self::ANCHORED, $value) !== 1) {
            return null;
        }

        return (int) substr($value, strlen($prefix));
    }
}
