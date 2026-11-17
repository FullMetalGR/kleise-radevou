<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Enum\BookingOutcome;
use App\Http\AdminLines;
use App\Http\AdminSession;
use App\Http\EachWrite;
use App\Http\LeftBecause;
use App\Http\Requests\MarkOutcomesRequest;
use App\Infrastructure\BookingTransitionFactory;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use LogicException;

/**
 * Home's To mark marking a selection of its rows at once, completed or a no-show: an ordinary form
 * post under manageBookings, like the booking dialog's own Completed and No-show, ungated by the
 * subscription as the booking lifecycle always is.
 *
 * Each booking is marked in turn, in the order sent, through the same transition the dialog's
 * buttons run, with the principal's actor, so each is its own guarded write with its own trail
 * row and, on Completed, the same thank-you email. A booking that does not go through is left as
 * it is and the rest go on (EachWrite), and the answer is a redirect back with one flash that
 * counts them: a success when every booking went, else an error, which the toaster keeps until it
 * is dismissed, saying how many went and how many were left and why.
 */
final readonly class MarkOutcomesController
{
    public const string COMPLETED = 'admin/home.to_mark.flashes.completed';

    public const string NO_SHOW = 'admin/home.to_mark.flashes.no_show';

    /** The first sentence when not one booking went. */
    public const string NONE = 'admin/home.to_mark.flashes.none';

    /** How many were left, and the reasons that follow it. */
    public const string LEFT = 'admin/home.to_mark.flashes.left';

    /** Each reason's count by why the booking was left, in LeftBecause's order. */
    public const array REASONS = [
        'changed' => 'admin/home.to_mark.flashes.changed',
        'busy' => 'admin/home.to_mark.flashes.busy',
        'failed' => 'admin/home.to_mark.flashes.failed',
    ];

    public function __construct(
        private CurrentTenant $current,
        private AdminSession $session,
        private BookingTransitionFactory $transitions,
        private EachWrite $each,
    ) {}

    public function __invoke(MarkOutcomesRequest $request): RedirectResponse
    {
        // The route's permission middleware has already answered a request with no principal.
        $principal = $this->session->principal($request) ?? throw new LogicException('A bulk mark ran without a principal; its route declares no permission.');
        $actor = $principal->actor();
        $outcome = $request->outcome();
        $marking = $this->transitions->markOutcome($this->current->get());
        $bookings = $request->bookings();
        $left = [];

        foreach ($bookings as $uuid) {
            $why = $this->each->attempt(fn () => $marking->mark($uuid, $outcome, $actor));

            if ($why !== null) {
                $left[] = $why;
            }
        }

        $request->session()->flash('flash', self::flash($outcome, count($bookings) - count($left), $left));

        return redirect()->back(fallback: AdminSession::at($request, AdminSession::PREFIX));
    }

    /**
     * The run's one sentence, or two: how many were marked, and, where any was left, how many and
     * why, each reason that occurred once with its own count, joined in LeftBecause's order.
     *
     * @param  list<LeftBecause>  $left
     * @return array{kind: string, message: string}
     */
    private static function flash(BookingOutcome $outcome, int $marked, array $left): array
    {
        $done = $marked === 0
            ? AdminLines::line(self::NONE)
            : trans_choice($outcome === BookingOutcome::Completed ? self::COMPLETED : self::NO_SHOW, $marked);

        if ($left === []) {
            return ['kind' => 'success', 'message' => $done];
        }

        $reasons = [];

        foreach (LeftBecause::cases() as $why) {
            $times = count(array_filter($left, fn (LeftBecause $one): bool => $one === $why));

            if ($times > 0) {
                $reasons[] = trans_choice(self::REASONS[$why->value], $times);
            }
        }

        return ['kind' => 'error', 'message' => $done.' '.trans_choice(self::LEFT, count($left), ['reasons' => implode(', ', $reasons)])];
    }
}
