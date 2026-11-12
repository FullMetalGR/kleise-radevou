<?php

use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\Decision;
use App\Http\ApprovalWindow;
use App\Http\DecisionWords;
use App\Http\OutcomeWords;
use Tests\Support\LangLines;

/*
 * What the outcome page says, which is the answer to every decision link that has nothing left
 * to decide: one that arrived late, one on a booking somebody else settled, one pressed twice,
 * and the one that just decided.
 *
 * Nine states and one sentence each, because a state nobody wrote a sentence for is a page that
 * says nothing at all to the person who clicked.
 *
 * The second sentence is the window's: a request whose window closed still reads as one waiting
 * for a decision, so the page says what the clock will make of it. That is on_approval_timeout
 * in words, a manage-only column, so a reader who may not be shown it gets the window and not
 * the policy.
 *
 * Each is a key of decision.php, and its sentence is read through a translator over the lang
 * directory, never spelled here.
 */

beforeEach(function () {
    LangLines::bind();
});

afterEach(function () {
    LangLines::release();
});

/**
 * A window that has closed with the sweeper not yet past it, carrying the policy where the
 * reader may be shown one.
 */
function outcomeClosed(?ApprovalTimeout $policy = null): ApprovalWindow
{
    return new ApprovalWindow(false, true, $policy);
}

/**
 * A window with nothing more to say: the booking is settled, or its window is still open.
 */
function outcomeSettled(): ApprovalWindow
{
    return new ApprovalWindow(false);
}

it('has a sentence for every state of the machine, and never the same one twice', function () {
    $sentences = array_map(fn (BookingStatus $status): string => LangLines::line(OutcomeWords::for($status, outcomeSettled())[0]), BookingStatus::cases());

    expect($sentences)->toHaveCount(count(BookingStatus::cases()))
        ->and(array_unique($sentences))->toHaveCount(count(BookingStatus::cases()))
        ->and(array_filter($sentences, fn (string $sentence): bool => trim($sentence) === ''))->toBe([]);
});

it('says where the booking stands, in words an owner reads rather than a column value: the key of its state', function (BookingStatus $status) {
    expect(OutcomeWords::for($status, outcomeSettled()))->toBe(['decision.outcome.'.$status->value])
        ->and(LangLines::line('decision.outcome.'.$status->value))->not->toBe($status->value)
        ->and(LangLines::line('decision.outcome.'.$status->value))->toEndWith('.');
})->with([
    'confirmed' => [BookingStatus::Confirmed],
    'rejected' => [BookingStatus::Rejected],
    'cancelled' => [BookingStatus::Cancelled],
    'expired' => [BookingStatus::Expired],
    'still waiting' => [BookingStatus::AwaitingApproval],
    'no-show' => [BookingStatus::NoShow],
    'awaiting payment' => [BookingStatus::AwaitingPayment],
]);

it('adds what the clock will make of a request whose window closed, and which of the two policies it is', function (ApprovalTimeout $policy) {
    expect(OutcomeWords::for(BookingStatus::AwaitingApproval, outcomeClosed($policy)))->toBe([
        'decision.outcome.awaiting_approval',
        'decision.window.'.$policy->value,
    ]);
})->with([
    'the slot is released' => [ApprovalTimeout::Expire],
    'the clock says yes' => [ApprovalTimeout::AutoApprove],
]);

it('says nothing about a window that has not closed, because a live request is one the page offers a button for', function () {
    expect(OutcomeWords::for(BookingStatus::AwaitingApproval, outcomeSettled()))->toHaveCount(1);
});

it('says the window closed and no more where the policy was withheld, because that column requires manageBookings', function () {
    // A staff-role approver reads the first two sentences and never the third fact. The same
    // answer covers a stored policy this build does not know, which is the one other way the
    // page can be handed no policy for a window that has closed.
    expect(OutcomeWords::for(BookingStatus::AwaitingApproval, outcomeClosed()))->toBe([
        'decision.outcome.awaiting_approval',
        'decision.window.closed',
    ]);
});

it('refuses to describe a window that is open and closed at once, or a policy for one still open', function () {
    expect(fn (): ApprovalWindow => new ApprovalWindow(true, true))->toThrow(InvalidArgumentException::class)
        ->and(fn (): ApprovalWindow => new ApprovalWindow(false, false, ApprovalTimeout::Expire))->toThrow(InvalidArgumentException::class);
});

it('spells no state and no decision in anything but ASCII in English, each a line of decision.php, the three window lines told apart', function () {
    $keys = [];

    foreach (BookingStatus::cases() as $status) {
        foreach (OutcomeWords::for($status, outcomeSettled()) as $key) {
            $keys[] = $key;
        }
    }

    foreach ([null, ...ApprovalTimeout::cases()] as $policy) {
        $keys[] = OutcomeWords::for(BookingStatus::AwaitingApproval, outcomeClosed($policy))[1] ?? '';
    }

    foreach (Decision::cases() as $decision) {
        $keys[] = DecisionWords::of($decision)['verb'];
        $keys[] = DecisionWords::of($decision)['consequence'];
    }

    $lines = array_map(fn (string $key): string => LangLines::line($key), $keys);

    foreach ($lines as $line) {
        expect(preg_match('/\A[\x20-\x7e]+\z/', $line))->toBe(1);
    }

    expect(array_unique($lines))->toHaveCount(count($keys));
});

/*
 * And the link's own payload: one decision per link, spelled as the route reads it back.
 */

it('spells its two cases the way the route matches them, and carries a reason on the rejection alone', function () {
    expect(Decision::spellings())->toBe(['approve', 'reject'])
        ->and(Decision::from('approve')->takesAReason())->toBeFalse()
        ->and(Decision::from('reject')->takesAReason())->toBeTrue();
});

it('gives the confirm page a word for the button and a box on the rejection alone, which is the enum read where the page asks it', function () {
    expect(DecisionWords::of(Decision::Approve))->toBe(['verb' => 'decision.decisions.approve.verb', 'consequence' => 'decision.decisions.approve.consequence', 'reason' => false])
        ->and(DecisionWords::of(Decision::Reject))->toBe(['verb' => 'decision.decisions.reject.verb', 'consequence' => 'decision.decisions.reject.consequence', 'reason' => true]);
});
