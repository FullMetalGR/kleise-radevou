<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingItemSnapshot;
use App\Site\SiteLanguage;

/**
 * What one booked item is called wherever a message names one.
 *
 * The rule: the staff member is named where their row is not the tenant's implicit one, and
 * left out where it is. That row stands for the business itself, which every message has
 * already named at the top, so "Haircut with Luna Hair Studio" would offer the salon as one of
 * its own stylists. A tenant that never bought the staff feature has exactly that row on every
 * item, so leaving the name out is also how such a tenant's messages read without anything
 * asking what they bought.
 *
 * It is one function because it has two callers that must agree: the lines a person reads and
 * the description inside the calendar file. A customer comparing the mail against the entry in
 * their diary is comparing the same sentence, and a rule spelled twice is a rule that will one
 * day be spelled two ways.
 *
 * The service with its staff member is one line of decision.php in the language given, :service
 * and :staff filled with the names as the tenant typed them, never a sentence spliced from
 * fragments; the service alone is the tenant's name as it stands.
 */
final readonly class ItemName
{
    public static function of(BookingItemSnapshot $item, SiteLanguage $language): string
    {
        return $item->staffIsImplicit
            ? $item->serviceName
            : DecisionLines::line('decision.facts.item', $language, ['service' => $item->serviceName, 'staff' => $item->staffName]);
    }
}
