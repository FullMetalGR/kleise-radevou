<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Clock;
use App\Http\ConsoleSession;
use Closure;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The declaration of a console route behind the door: every console route but the door's three. It
 * is a check, not a label: a request with no operator on the console guard goes to the door, as a
 * guest of the admin goes to the admin's.
 *
 * It runs the session's two timeouts besides, which stand in for a second factor with the sign-in
 * notice. A request more than thirty minutes after the last one admitted on the session, or more
 * than eight hours after the door opened it, ends the session and shows the door with that case's
 * sentence as an error; any other request is admitted and starts the thirty minutes again. Both are
 * read from the Clock port, so each is proven on a moved clock. Whenever eight hours have passed
 * since the sign-in the sentence is the ceiling's, whatever the idle; the idle sentence shows
 * inside the eight hours alone. A session naming an operator but carrying no instant it can read is
 * one that has run out, and reads as idle unless its sign-in instant is readable and eight hours
 * old. The framework's own session lifetime stays as configured and is not this.
 *
 * A fourth guard beside them: a live session whose password fingerprint no longer matches the
 * operator's row, the password set anew since the sign-in, ends at that request with a sentence of
 * its own; one carrying no fingerprint has run out and reads as idle. The row is the one the guard
 * has already read, so the comparison costs no query.
 *
 * Carried on the route and never on a group, as every declaration is.
 */
final readonly class RequiresOperator
{
    public const string NAME = 'operator';

    public const string IDLE = 'You were signed out after thirty minutes without activity.';

    public const string CEILING = 'You were signed out after eight hours. Sign in again.';

    public const string PASSWORD_CHANGED = 'You were signed out because your password was changed. Sign in with the new one.';

    /** How long a session may sit unused, from the last request admitted on it. */
    private const string IDLE_FOR = 'PT30M';

    /** How long a session lasts from the sign-in, whatever is done with it. */
    private const string CEILING_AFTER = 'PT8H';

    public function __construct(private ConsoleSession $session, private Clock $clock) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $operator = $this->session->operator($request);

        if ($operator === null) {
            return $this->session->toDoor($request);
        }

        $now = $this->clock->now();
        $lapse = $this->lapse($request, $now) ?? match ($this->session->passwordUnchanged($request, $operator)) {
            true => null,
            false => self::PASSWORD_CHANGED,
            null => self::IDLE,
        };

        if ($lapse !== null) {
            return $this->session->end($request, ['kind' => 'error', 'message' => $lapse]);
        }

        $this->session->seen($request, $now);

        return $next($request);
    }

    /**
     * The sentence the session ran out with, null while it is live.
     */
    private function lapse(Request $request, DateTimeImmutable $now): ?string
    {
        $signedInAt = $this->session->signedInAt($request);
        $lastSeenAt = $this->session->lastSeenAt($request);

        if ($signedInAt !== null && $now > $signedInAt->add(new DateInterval(self::CEILING_AFTER))) {
            return self::CEILING;
        }

        if ($signedInAt === null || $lastSeenAt === null || $now > $lastSeenAt->add(new DateInterval(self::IDLE_FOR))) {
            return self::IDLE;
        }

        return null;
    }
}
