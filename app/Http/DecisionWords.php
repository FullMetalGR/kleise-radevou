<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Enum\Decision;

/**
 * What the confirm page says about the one decision its link carries: the word on the button,
 * what pressing it will do, and whether there is a box to say why.
 *
 * It is the other half of OutcomeWords and is here for the same reason: the enum is the fact
 * and this is the page's words, so a decision an emailed link and a queued job both name does
 * not carry a page's copy around with it.
 *
 * The verb and the consequence are display keys of decision.php, which DecisionPages reads in the
 * page's language; the box is a fact and stays one. Every value the template prints is built in
 * PHP rather than read off an object in the view, the way the booking's own facts are, so the
 * page is a list of strings and a rule can only be changed in one place.
 */
final readonly class DecisionWords
{
    /**
     * @return array{verb: string, consequence: string, reason: bool}
     */
    public static function of(Decision $decision): array
    {
        // The verb is the booking dialog's action; the consequence is what pressing it does,
        // said in the owner's terms rather than the status machine's, and each sentence is
        // true: an approval lands the booking confirmed and the slot stays where it is, a
        // rejection ends the request and the items stop blocking. The box is the reason the
        // enum says this decision takes, asked here because the page is what offers it and
        // answered there because whether a rejection carries a note is not a fact about a page.
        return match ($decision) {
            Decision::Approve => [
                'verb' => 'decision.decisions.approve.verb',
                'consequence' => 'decision.decisions.approve.consequence',
                'reason' => $decision->takesAReason(),
            ],
            Decision::Reject => [
                'verb' => 'decision.decisions.reject.verb',
                'consequence' => 'decision.decisions.reject.consequence',
                'reason' => $decision->takesAReason(),
            ],
        };
    }
}
