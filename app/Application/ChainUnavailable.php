<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Booking\StaffBlock;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * The chain cannot be placed where it was asked to go, because the tenant is not open to it
 * there: the start is inside the largest lead time among the segments, or on a date past the
 * smallest horizon, or the member who would carry a segment is outside their hours or on a
 * blackout. Named by segment so the API can answer with it, the chain's start being segment
 * zero's.
 *
 * A booking being moved reaches it two more ways, because a move is planned from what was sold
 * rather than from a list the customer is choosing off: a segment whose service the catalog no
 * longer offers, and a segment whose sold member no longer performs it. Both are the tenant
 * having changed their mind about what they sell, which is the same kind of thing as a blackout
 * written since, and neither leaves the customer stuck, since cancelling still works.
 *
 * Both sides of the lock raise it, and that is deliberate. The planner raises it from the
 * advisory masks before any transaction opens; the re-validation raises it again from the masks
 * rebuilt under the lock, where a blackout written since the advisory read appears for the
 * first time. It is one answer because it is one thing that is wrong, and the code is the
 * machine reason: a blackout found under the lock is not somebody else's booking, and answering
 * the customer that their time was just taken would be a sentence about a thing that did not
 * happen. What is not this is SegmentConflict, which is capacity: another booking already
 * occupies the range.
 *
 * Nothing is written either way. Before the lock nothing was locked; under it the transaction
 * rolls back with the refusal.
 *
 * Each constructor names its rule, one of the constants below, for a caller with something
 * better to do than say the chain cannot go there: the admin's move answers it beside the code,
 * so the Move form can say whether the start has passed, is too soon, too far ahead, off the
 * grid, or whether the service or the member is gone. The rule is a machine word and never a
 * sentence.
 */
final class ChainUnavailable extends RuntimeException
{
    public const string PAST = 'past';

    public const string LEAD_TIME = 'lead_time';

    public const string HORIZON = 'horizon';

    public const string OFF_GRID = 'off_grid';

    public const string SERVICE_GONE = 'service_gone';

    public const string STAFF_GONE = 'staff_gone';

    public const string NOBODY = 'nobody';

    public const string UNDER_LOCK = 'under_lock';

    private function __construct(
        public readonly int $segment,
        public readonly string $rule,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * A start that has already passed: told apart from the lead time, which may stop binding
     * the business, while a start in the past stays refused for everyone.
     */
    public static function inThePast(DateTimeImmutable $now): self
    {
        return new self(0, self::PAST, sprintf(
            'The chain cannot start before %s UTC, which is now.',
            $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ));
    }

    public static function insideLeadTime(DateTimeImmutable $earliest): self
    {
        return new self(0, self::LEAD_TIME, sprintf(
            'The chain cannot start before %s UTC, its lead time.',
            $earliest->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ));
    }

    public static function pastHorizon(string $lastDay): self
    {
        return new self(0, self::HORIZON, sprintf('The chain cannot start on a date after %s, its horizon.', $lastDay));
    }

    /**
     * @param  int  $segment  zero-based, the first segment nobody can start at the instant, or zero under the same-staff preference
     */
    public static function nobodyAt(int $segment, DateTimeImmutable $start): self
    {
        return new self(self::zeroBased($segment), self::NOBODY, sprintf(
            'No eligible staff member can start segment %d of a chain starting at %s UTC.',
            $segment,
            $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ));
    }

    /**
     * The re-validation's refusal: under the lock, the member assigned to the segment cannot
     * hold the contended range, though no item of anybody's overlaps it. The range is the one
     * the item occupies, buffers included, which is what the masks were asked about.
     *
     * @param  int  $segment  zero-based, the item's position in the chain
     */
    public static function underLock(int $segment, StaffBlock $block): self
    {
        $utc = new DateTimeZone('UTC');

        return new self(self::zeroBased($segment), self::UNDER_LOCK, sprintf(
            'Segment %d cannot be held for staff member %d between %s and %s UTC: the range is outside their availability.',
            $segment,
            $block->staffId,
            $block->start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $block->end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ));
    }

    /**
     * A move whose segment names a service the catalog no longer offers. A hold asking for one
     * is a 404, because the customer is choosing from a list and the list has changed under
     * them; a booking already sold is not choosing anything, and the answer it needs is that
     * this appointment cannot be moved, which leaves cancelling as the way out. So it is the
     * same refusal as a chain the tenant is not open to, named by segment like the rest.
     *
     * @param  int  $segment  zero-based, the item's position in the chain
     */
    public static function serviceGone(int $segment, int $serviceId): self
    {
        return new self(self::zeroBased($segment), self::SERVICE_GONE, sprintf(
            'Segment %d names service %d, which the catalog no longer offers, so the booking cannot be moved.',
            $segment,
            $serviceId,
        ));
    }

    /**
     * A move whose segment names a member who no longer performs its service, whether because
     * they were deactivated or because the assignment was taken away. The sold member is kept
     * rather than re-picked, so there is nobody else to place the segment on and the move is
     * refused rather than quietly handed to a stranger.
     *
     * @param  int  $segment  zero-based, the item's position in the chain
     */
    public static function staffGone(int $segment, int $staffId): self
    {
        return new self(self::zeroBased($segment), self::STAFF_GONE, sprintf(
            'Segment %d was sold to staff member %d, who no longer performs its service, so the booking cannot be moved.',
            $segment,
            $staffId,
        ));
    }

    public static function offGrid(DateTimeImmutable $start, int $granularityMinutes): self
    {
        return new self(0, self::OFF_GRID, sprintf(
            'The chain cannot start at %s UTC: a start lies on the tenant\'s %d-minute grid.',
            $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            $granularityMinutes,
        ));
    }

    /**
     * A segment index as every constructor here takes one: zero-based, and never negative. A
     * negative index is a caller counting from the wrong end, which is a programming error and
     * is raised as one rather than travelling to a customer as a segment nobody has.
     */
    private static function zeroBased(int $segment): int
    {
        if ($segment < 0) {
            throw new InvalidArgumentException(sprintf('A segment index is zero-based and never negative, not %d.', $segment));
        }

        return $segment;
    }
}
