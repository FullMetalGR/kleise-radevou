<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\ChainUnavailable;
use App\Application\NoSuchSegment;
use App\Application\StaffNotEligible;
use Throwable;

/**
 * The envelope a refused business move is answered with, the write's and the read's verdict's
 * alike, so the two cannot answer one request with two codes. ErrorEnvelope's answer for every
 * refusal it already words, with three things only the move needs beside it.
 *
 * A chain the move cannot place carries its rule beside the segment, the planner's or the
 * catalog's (ChainUnavailable's constants: past, lead_time, horizon, off_grid, service_gone,
 * staff_gone, and nobody and under_lock where neither names one the screen could act on), and a
 * sentence for the rule where one says more than the general one. The lead time and the horizon
 * bind customers alone, so no business move meets either; were one to, it reads the general
 * sentence. A segment given to a member
 * who does not perform its service is staff_not_eligible at 400, the segment and the member
 * beside it, where the public pick's refusal is invalid_request, since the Move form has
 * something better to do with it than show the sentence. A segment the booking does not have is
 * the request's fault, invalid_request at 400.
 */
final readonly class MoveRefusal
{
    public const string STAFF_NOT_ELIGIBLE = 'staff_not_eligible';

    /** The rules a sentence of its own words, api.php's move group by the rule's name. */
    private const array WORDED = [
        ChainUnavailable::PAST,
        ChainUnavailable::OFF_GRID,
        ChainUnavailable::SERVICE_GONE,
        ChainUnavailable::STAFF_GONE,
    ];

    public static function for(Throwable $e): ErrorEnvelope
    {
        if ($e instanceof ChainUnavailable) {
            $detail = in_array($e->rule, self::WORDED, true) ? ApiLines::line('api.move.'.$e->rule) : ApiLines::line(ErrorEnvelope::UNAVAILABLE_DETAIL);

            return new ErrorEnvelope('unavailable', 409, $detail, ['segment' => $e->segment, 'rule' => $e->rule]);
        }

        if ($e instanceof StaffNotEligible) {
            return new ErrorEnvelope(self::STAFF_NOT_ELIGIBLE, 400, ApiLines::line('api.move.staff_not_eligible'), ['segment' => $e->segment, 'staff_id' => $e->staffId]);
        }

        if ($e instanceof NoSuchSegment) {
            return new ErrorEnvelope('invalid_request', 400, ApiLines::line('api.move.no_such_segment'));
        }

        return ErrorEnvelope::for($e);
    }
}
