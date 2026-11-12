<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\BookingUnknown;
use App\Application\ChainUnavailable;
use App\Application\KeySetMoved;
use App\Application\ServiceNotBookable;
use App\Application\StaffNotEligible;
use App\Application\TransitionRefused;
use App\Application\UnacceptedWarning;
use App\Application\WriteContended;
use App\Billing\PlanChangeRefused;
use App\Billing\ResubscribeRefused;
use App\Domain\Booking\MaskCrossing;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Booking\TransitionRefusal;
use App\Infrastructure\Db\Busy;
use App\Site\SiteLanguage;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The error envelope, for every error under /api/v1: code, message and data, the data carrying
 * the status and a detail sentence written to be shown verbatim. The UI shows detail and
 * branches on code only where it has something better to do than show a sentence; message
 * repeats the code and is the fallback.
 *
 * The refusals the booking engine raises are answered by type, each with a sentence of its own
 * and nothing from the exception itself, whose message is developer prose naming ids and UTC
 * instants. A 409 is normal and expected, and carries the zero-based segment that failed; a 503
 * carries a Retry-After, because a row lock that was not granted is worth trying again in a
 * moment and everything else 5xx is not.
 *
 * A validation failure is 400 with the real per-field sentence as its detail, not the
 * framework's 422 with a field map. A 404 never says whether the thing is unknown, inactive or
 * hidden. A 429 is worded so the booking app can show it on the form the customer is looking
 * at. A 500 carries one fixed sentence and nothing from the exception, whatever the debug
 * setting, because a stack trace on a public API is a disclosure and not a diagnostic.
 *
 * The sentences are keys of lang/{en,el}/api.php: each constant below keeps its name and holds
 * its key, and so does each status's own sentence, and the detail is read through the
 * translator when the envelope is built, in the request's language, the locale SetTenantLocale
 * set (on /api/v1 the lang parameter, else the tenant default; under /admin/api the user's), or
 * in the language a caller names, English on the webhooks, since a provider's delivery log is
 * no reader. A caller that builds an envelope with its own detail hands in the sentence it
 * means, read where that caller reads it.
 */
final readonly class ErrorEnvelope
{
    public const string SERVER_ERROR_DETAIL = 'api.envelope.server_error';

    public const string RATE_LIMITED_DETAIL = 'api.envelope.rate_limited';

    public const string OVERLAP_DETAIL = 'api.envelope.overlap';

    public const string UNAVAILABLE_DETAIL = 'api.envelope.unavailable';

    public const string SERVICE_GONE_DETAIL = 'api.envelope.service_gone';

    public const string STAFF_NOT_ELIGIBLE_DETAIL = 'api.envelope.staff_not_eligible';

    public const string BUSY_DETAIL = 'api.envelope.busy';

    public const string MISSING_TOKEN_DETAIL = 'api.envelope.missing_token';

    public const string HOLD_EXPIRED_DETAIL = 'api.envelope.hold_expired';

    public const string NOT_CONFIRMABLE_DETAIL = 'api.envelope.not_confirmable';

    public const string PAYMENT_REQUIRED_DETAIL = 'api.envelope.payment_required';

    public const string NOT_CANCELLABLE_DETAIL = 'api.envelope.not_cancellable';

    public const string NOT_RESCHEDULABLE_DETAIL = 'api.envelope.not_reschedulable';

    public const string NOT_RELEASABLE_DETAIL = 'api.envelope.not_releasable';

    public const string HOLD_LAPSED_DETAIL = 'api.envelope.hold_lapsed';

    public const string WINDOW_CLOSED_DETAIL = 'api.envelope.window_closed';

    public const string NOT_APPROVABLE_DETAIL = 'api.envelope.not_approvable';

    public const string NOT_REJECTABLE_DETAIL = 'api.envelope.not_rejectable';

    public const string NOT_CONFIRMED_DETAIL = 'api.envelope.not_confirmed';

    public const string TOO_EARLY_DETAIL = 'api.envelope.too_early';

    public const string UNACCEPTED_WARNING_DETAIL = 'api.envelope.unaccepted_warning';

    /** The hold's refusal of an address the business blocked from booking online. */
    public const string BLOCKED_DETAIL = 'api.envelope.blocked';

    /**
     * How long a caller waits before retrying a 503 busy, in seconds. A lock was held by
     * somebody who is about to be done with it, not an outage.
     */
    public const string RETRY_AFTER_BUSY = '1';

    /**
     * @param  array<string, mixed>  $data  keys beside status and detail, such as the failing segment of a chain
     * @param  array<string, mixed>  $headers  response headers the error carries, such as Retry-After
     */
    public function __construct(
        public string $code,
        public int $status,
        public string $detail,
        public array $data = [],
        public array $headers = [],
    ) {}

    /**
     * The envelope a throwable is answered with, its detail read in the language given, else in
     * the request's, the application's locale.
     */
    public static function for(Throwable $e, ?SiteLanguage $language = null): self
    {
        $refusal = self::refusalFor($e, $language);

        if ($refusal !== null) {
            return $refusal;
        }

        if ($e instanceof ValidationException) {
            $sentence = $e->validator->errors()->first();

            return new self('invalid_request', 400, $sentence === '' ? ApiLines::line(self::detailFor(400), language: $language) : $sentence);
        }

        if ($e instanceof AuthenticationException) {
            return new self('unauthenticated', 401, ApiLines::line(self::detailFor(401), language: $language));
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return new self(self::codeFor($status), $status, ApiLines::line(self::detailFor($status), language: $language), headers: self::headersOf($e));
        }

        return new self('server_error', 500, ApiLines::line(self::SERVER_ERROR_DETAIL, language: $language));
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse([
            'code' => $this->code,
            'message' => $this->code,
            'data' => ['status' => $this->status, 'detail' => $this->detail, ...$this->data],
        ], $this->status, $this->headers);
    }

    /**
     * The refusals raised by name, by type: a service the catalog no longer offers, a pick who
     * does not perform it, a chain the tenant cannot start where it was asked to, a conflict
     * found under the lock, a guard refusing a transition on what the lock found, a booking
     * nobody has, a row lock that was not granted within the timeout or a write whose items
     * would not stay still, and a request to a guarded route that presented no credential at
     * all. Everything else is not a refusal and is answered by the rules below.
     *
     * The 404 says which of unknown and inactive it was no more than any other 404 does. The
     * two 409s are two different things and not two spellings of one: overlap is capacity, a
     * range already taken, and unavailable is the tenant not being open to the range at all.
     * Both name the segment that failed, so a chain's refusal points at one of its items.
     *
     * A business move that would cross a member's hours or a closure, its warning not accepted,
     * is a 409 too, normal and expected: it names every crossing, the segment, the member and
     * which of the two, so the screen can say where and ask again with the warning accepted.
     *
     * A guard's refusal answers with its own machine reason as the code. A booking nobody has
     * is the 404 a failed credential gets, word for word, so a guest route is no kind of
     * existence oracle whichever way it found out. And a write whose items would not stay still
     * is busy, the same answer as a lock that was not granted, because it is the same thing
     * happening to the caller. The signal that rolls one attempt back is answered the same way
     * for the same reason and so that it cannot be a 500: it is caught inside the shared
     * implementation today and a guard closure that ever let one out would otherwise reach a
     * customer as a crash rather than as the contention it is.
     */
    private static function refusalFor(Throwable $e, ?SiteLanguage $language): ?self
    {
        $line = fn (string $key): string => ApiLines::line($key, language: $language);

        return match (true) {
            $e instanceof ServiceNotBookable => new self('not_found', 404, $line(self::SERVICE_GONE_DETAIL)),
            $e instanceof StaffNotEligible => new self('invalid_request', 400, $line(self::STAFF_NOT_ELIGIBLE_DETAIL)),
            $e instanceof ChainUnavailable => new self('unavailable', 409, $line(self::UNAVAILABLE_DETAIL), ['segment' => $e->segment]),
            $e instanceof SegmentConflict => new self('overlap', 409, $line(self::OVERLAP_DETAIL), ['segment' => $e->segment]),
            $e instanceof UnacceptedWarning => new self('unaccepted_warning', 409, $line(self::UNACCEPTED_WARNING_DETAIL), ['crossings' => self::crossingsOf($e->crossings)]),
            $e instanceof TransitionRefused => new self($e->reason->value, self::statusOf($e->reason), $line(self::sentenceOf($e->reason))),
            $e instanceof BookingUnknown => new self('not_found', 404, $line(self::detailFor(404))),
            $e instanceof Busy, $e instanceof WriteContended, $e instanceof KeySetMoved => new self('busy', 503, $line(self::BUSY_DETAIL), headers: ['Retry-After' => self::RETRY_AFTER_BUSY]),
            $e instanceof MissingManageToken => new self('invalid_request', 400, $line(self::MISSING_TOKEN_DETAIL)),
            $e instanceof PlanChangeRefused => new self($e->reason, $e->reason === PlanChangeRefused::UNKNOWN ? 400 : 409, $e->sentence($language)),
            $e instanceof ResubscribeRefused => new self($e->reason, 409, $line($e->key)),
            default => null,
        };
    }

    /**
     * The crossings a refusal names, as the wire spells them.
     *
     * @param  list<MaskCrossing>  $crossings
     * @return list<array{segment: int, staff_id: int, kind: string}>
     */
    public static function crossingsOf(array $crossings): array
    {
        return array_map(fn (MaskCrossing $crossing): array => ['segment' => $crossing->segment, 'staff_id' => $crossing->staffId, 'kind' => $crossing->kind->value], $crossings);
    }

    /**
     * What a refused transition answers with. A confirm whose hold ran out is 410: the thing
     * that request was about is gone, and the booking app treats that one status specially,
     * forcing its expiry flag however the customer's own clock reads. That is the single
     * exception and it belongs to the one reason. Every other guard refusing a transition is
     * 409, normal and expected, and a lapsed hold refusing some other act is one of them rather
     * than a second 410: the difference between the two is which act was asked for and what the
     * client does next. A policy window that has closed is 403, the other half of the same
     * rule: the state would allow the act and the clock does not, and it is the business, not
     * the booking, that says no. Money owed is 402.
     *
     * An outcome asked for before the appointment starts is a clock refusal too and is still
     * 409, because 4.3's 403 is a policy window, a missing permission or the subscription gate,
     * and this is none of the three: no window was configured, nobody lacks a right, and the
     * same user may do it the moment the start passes. It is a guard refusing a transition.
     */
    private static function statusOf(TransitionRefusal $reason): int
    {
        return match ($reason) {
            TransitionRefusal::HoldExpired => 410,
            TransitionRefusal::NotConfirmable,
            TransitionRefusal::NotCancellable,
            TransitionRefusal::NotReschedulable,
            TransitionRefusal::NotReleasable,
            TransitionRefusal::HoldLapsed,
            TransitionRefusal::NotApprovable,
            TransitionRefusal::NotRejectable,
            TransitionRefusal::NotConfirmed,
            TransitionRefusal::TooEarly => 409,
            TransitionRefusal::WindowClosed => 403,
            TransitionRefusal::PaymentRequired => 402,
        };
    }

    /**
     * The sentence each refusal is shown as, by its key, written for the customer reading it and
     * not for the developer reading the exception. Nothing from the exception's own message
     * reaches here: that names identifiers and states and is written for a log.
     */
    private static function sentenceOf(TransitionRefusal $reason): string
    {
        return match ($reason) {
            TransitionRefusal::HoldExpired => self::HOLD_EXPIRED_DETAIL,
            TransitionRefusal::NotConfirmable => self::NOT_CONFIRMABLE_DETAIL,
            TransitionRefusal::NotCancellable => self::NOT_CANCELLABLE_DETAIL,
            TransitionRefusal::NotReschedulable => self::NOT_RESCHEDULABLE_DETAIL,
            TransitionRefusal::NotReleasable => self::NOT_RELEASABLE_DETAIL,
            TransitionRefusal::HoldLapsed => self::HOLD_LAPSED_DETAIL,
            TransitionRefusal::WindowClosed => self::WINDOW_CLOSED_DETAIL,
            TransitionRefusal::PaymentRequired => self::PAYMENT_REQUIRED_DETAIL,
            TransitionRefusal::NotApprovable => self::NOT_APPROVABLE_DETAIL,
            TransitionRefusal::NotRejectable => self::NOT_REJECTABLE_DETAIL,
            TransitionRefusal::NotConfirmed => self::NOT_CONFIRMED_DETAIL,
            TransitionRefusal::TooEarly => self::TOO_EARLY_DETAIL,
        };
    }

    /**
     * The headers the exception carries, such as the rate limiter's Retry-After, by name.
     *
     * @return array<string, mixed>
     */
    private static function headersOf(HttpExceptionInterface $e): array
    {
        $headers = [];

        foreach ($e->getHeaders() as $name => $value) {
            $headers[(string) $name] = $value;
        }

        return $headers;
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'invalid_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            429 => 'rate_limited',
            // Not "unavailable": that code is the 409 of a chain the tenant is not open to,
            // which the booking app shows a sentence for, and the code is the machine
            // reason. Two unrelated answers under one reason is one reason too few.
            503 => 'service_unavailable',
            default => $status >= 500 ? 'server_error' : 'request_refused',
        };
    }

    /**
     * The key of the sentence a status is answered with where no reason names its own.
     */
    private static function detailFor(int $status): string
    {
        return match ($status) {
            400 => 'api.status.bad_request',
            401 => 'api.status.unauthenticated',
            403 => 'api.status.forbidden',
            404 => 'api.status.not_found',
            405 => 'api.status.method_not_allowed',
            429 => self::RATE_LIMITED_DETAIL,
            503 => 'api.status.service_unavailable',
            default => $status >= 500 ? self::SERVER_ERROR_DETAIL : 'api.status.refused',
        };
    }
}
