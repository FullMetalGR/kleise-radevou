<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\BookingSnapshot;
use App\Notifications\BookingLines;
use App\Notifications\CustomerFacts;
use App\Notifications\ItemName;
use App\Notifications\TenantClock;
use App\Notifications\TenantTime;
use App\Site\SiteLanguage;

/**
 * What a booking looks like on a page of this host that is not the booking app: who it is for,
 * what was booked and when, and what it came to.
 *
 * It is the message renderings and not a second set. What an item is called is ItemName's rule,
 * an instant is TenantTime's, and the total is BookingLines'. An owner comparing the approval
 * request in their mail against the page its link opened is comparing the same sentences, and a
 * rule spelled twice is a rule that will one day be spelled two ways.
 *
 * Every string is in the page's language: the labels, an item's name with its staff member, the
 * instants ICU spells in the tenant's clock with the tenant's date order and dial, the total
 * and the line naming the clock. The names the tenant typed are as typed.
 *
 * **What the customer's block holds is decided once, on a page as on every surface.** The email
 * and the phone require manageBookings, which approving a booking does not qualify as, so a
 * staff user's page names the customer and says no more. That block is CustomerFacts', the one
 * place the rule is written, and the answer arrives with the caller, read from the role of the
 * user the link names, rather than being guessed here.
 */
final readonly class BookingFacts
{
    /**
     * @return array{customer: list<array{label: string, value: string}>, items: list<array{what: string, when: string}>, total: string, zone: string}
     */
    public static function of(BookingSnapshot $booking, TenantClock $clock, bool $managesBookings, SiteLanguage $language): array
    {
        $items = [];

        foreach ($booking->items as $item) {
            // Every item is timed. A chain's segments can sit hours apart with the staff member
            // free in between, so one time for the whole of it would be a time the later
            // segments are not at.
            $items[] = ['what' => ItemName::of($item, $language), 'when' => TenantTime::instant($item->start, $clock, $language)];
        }

        $customer = [];

        foreach (CustomerFacts::of($booking->customer, $managesBookings, $language) as $fact => $value) {
            $customer[] = ['label' => CustomerFacts::label($fact, $language), 'value' => $value];
        }

        return [
            'customer' => $customer,
            'items' => $items,
            'total' => BookingLines::total($booking, $language),
            'zone' => TenantTime::footerForTheBusiness($clock->zone, $language),
        ];
    }
}
