<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Enum\Decision;
use App\Tenancy\Role;
use DateTimeImmutable;

/**
 * A decision link that verified: which booking, which decision, which user it decides as, what
 * that user may be shown, and when the link stops working.
 *
 * It is built only by the guard, so holding one is the fact that the signature was this
 * deployment's and that the guard's three checks passed. The signature is the whole
 * authentication here, so this object is the credential in the same sense that a verified
 * manage token is: everything downstream acts on what it says and asks nothing again.
 *
 * The role travels with it because who may see a customer's contact details is one decision on
 * every surface and a page is a surface: the customer's email and phone reach the page only
 * where the user the link names holds manageBookings. It is the role rather than a flag,
 * because the decision pages ask two different questions of it - what may be shown, and whether
 * a staff user is in scope - and one boolean cannot answer both.
 */
final readonly class SignedDecision
{
    public function __construct(
        public string $uuid,
        public Decision $decision,
        public int $userId,
        public Role $role,
        public DateTimeImmutable $expiresAt,
    ) {}

    /**
     * Whether the link is past the window it was minted for. Half-open like every other range
     * in this codebase: a link asked for at exactly its expiry has lapsed.
     *
     * The instant is passed in rather than read from a clock here, because there is one clock
     * and it is the application's, and the pages are the ones that hold it.
     */
    public function hasLapsed(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /**
     * Whether the customer's email and phone may be printed on the page.
     */
    public function managesBookings(): bool
    {
        return $this->role->mayManageBookings();
    }
}
