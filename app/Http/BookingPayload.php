<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\BookingItemSnapshot;
use App\Application\Dto\BookingRecord;
use App\Application\Dto\BookingSnapshot;
use DateTimeImmutable;
use DateTimeZone;

/**
 * What a caller may see of a booking and its items, on every surface: the one class that
 * decides. It is a whitelist. Every column of bookings and of booking_items is named here as
 * visible to a guest holding a valid manage token, visible to every viewer of the admin but not
 * to the guest, visible only to a user with manageBookings, or withheld from everybody, and a
 * test reads both live tables and fails on a column this class does not classify. A column
 * added later is therefore invisible until somebody decides what it is.
 *
 * The buckets are a ladder and not lists of readers: the guest's columns are the booking's own
 * story, a viewer of the admin sees those and the notes the business keeps for its own staff,
 * and a user with manageBookings sees all of that and the operational columns besides.
 * customer_email and customer_phone sit in the guest bucket because the booking is the guest's
 * own, and they are kept from every staff surface that is not manageBookings, so they are the
 * one pair a viewer without it misses although the guest sees them. The serial ids and
 * manage_token_hash reach nobody, ever, and neither does block_span, which restates two columns
 * that are classified themselves, nor blocks, the flag the write protocol maintains for its own
 * benefit.
 *
 * The wire spells a column under its own name, with three deliberate exceptions: an item's
 * start_utc and end_utc are start and end, because every instant on this API is UTC and the
 * suffix would say it twice, and the booking's manage token is not a column at all. Instants
 * are RFC 3339 in UTC, and a price is minor units beside the currency the booking carries,
 * never a formatted string.
 */
final readonly class BookingPayload
{
    /**
     * The columns of bookings a guest holding a valid manage token is shown, each under its
     * own name.
     */
    public const array GUEST = [
        'uuid', 'status', 'hold_class', 'hold_expires_at', 'customer_name', 'customer_email',
        'customer_phone', 'total_minor', 'currency',
    ];

    /**
     * The columns of bookings every viewer of the admin is shown, with manageBookings or without
     * it, and the guest is not: the reason a request was rejected. It is the owner's note to
     * their own staff, withheld from the guest view alone, the status sentence being what the
     * customer is told; the booking dialog shows it in its facts wherever one exists. So the
     * staff member without manageBookings, the reader the note is written for, receives it.
     *
     * Beside it the surface the booking came through and the channel its link was tagged with:
     * the business's own reading of where its bookings come from, shown in the dialog's facts,
     * and nothing a customer needs. The customer's note is no column of bookings and never rides
     * this payload: the dialog reads it through its own reader.
     */
    public const array VIEWER = ['rejection_reason', 'source', 'channel'];

    /**
     * The columns of bookings that need manageBookings: how the booking is to be paid for and
     * what it agreed to at hold time, the approval trail, the provider's reference, and the
     * timestamps.
     */
    public const array MANAGE_ONLY = [
        'payment_mode', 'requires_approval', 'on_approval_timeout', 'approved_at', 'approved_by',
        'payment_ref', 'created_at', 'updated_at',
    ];

    /**
     * The columns of bookings that reach no payload: the serial id, which is never the public
     * identifier, the hash of the customer's credential, and its ciphertext the pay hop seals
     * for the confirmation after a payment; and the language the booking was made in, which the
     * customer's mails read on the server and no answer or prop carries.
     */
    public const array WITHHELD = ['id', 'manage_token_hash', 'manage_token_sealed', 'language'];

    /**
     * The guest columns a staff surface shows only to a user with manageBookings: the ways to
     * reach the customer. The admin's view drops them without it, and so does anything that
     * would answer a question about them, the list's search among others.
     */
    public const array CONTACT = ['customer_email', 'customer_phone'];

    /**
     * The customer columns the bookings list's search reads, before the viewer is asked.
     */
    public const array SEARCHED = ['customer_name', 'customer_email'];

    /**
     * The columns of booking_items a guest is shown: where the item sits in the chain, what it
     * is, who performs it, the range sold and the price fixed at hold time.
     */
    public const array ITEM_GUEST = ['sort', 'service_id', 'staff_id', 'start_utc', 'end_utc', 'price_minor'];

    /**
     * The columns of booking_items that need manageBookings: the range the staff member is
     * actually occupied for, which is the customer's range widened by buffers and is
     * deliberately not the customer's business, the end of a processing gap, and the
     * timestamps.
     */
    public const array ITEM_MANAGE_ONLY = [
        'block_start_utc', 'block_end_utc', 'processing_ends_utc', 'created_at', 'updated_at',
    ];

    /**
     * The columns of booking_items that reach nobody: the serial id, the container's serial id,
     * the generated range that restates two columns already classified, and the blocking flag
     * the write protocol maintains for itself.
     */
    public const array ITEM_WITHHELD = ['id', 'booking_id', 'block_span', 'blocks'];

    /**
     * The facts of an audit entry the admin's trail shows, named by AuditKey: the statuses either
     * side of a transition, the hold class and expiry it concerned, how many items it held, the
     * starts a move left and took, of a move by the business, the segments it gave to other
     * members, the warnings it overrode and whether it told the customer, and of a payment, the
     * amount it took in minor units with the currency it was paid in, which the trail shows as
     * one line in that currency at its scale. The trail is a surface like any other, so what
     * reaches it is decided here and not by the closed set the writers happen to use: a key
     * added to AuditKey is withheld from the trail until it is classified here, and a test walks
     * the set. Every one of them is a fact about the booking and none names the customer, which
     * is why the trail has one audience: every viewer of the dialog.
     */
    public const array TRAIL_FACTS = ['status_from', 'status_to', 'hold_class', 'hold_expires_at', 'item_count', 'starts_at', 'moved_from', 'staff_changed', 'overridden', 'customer_told', 'paid_minor', 'paid_currency'];

    /**
     * The facts of an audit entry that reach no trail, so that withholding a key is a decision
     * written down rather than an omission: payment_named, which the payment webhook reads to
     * tell a late payment from another notice of the confirming one and which says nothing a
     * person reading the trail can act on; and session_cleared, which the pay hop reads to keep
     * a moved hold from being lengthened again and which says nothing of the move a person
     * would act on.
     */
    public const array TRAIL_WITHHELD = ['payment_named', 'session_cleared'];

    /**
     * The guest view: what the customer who holds the booking's manage token is shown of it. The
     * three customer columns are null together once the customer is erased, which no guest
     * reaches, since the erasure nulled the token and took the address every session is matched
     * by; the admin's view is built from this one and shows the nulls as "Customer erased".
     *
     * @return array{uuid: string, status: string, hold_class: string|null, hold_expires_at: string|null, customer_name: string|null, customer_email: string|null, customer_phone: string|null, total_minor: int, currency: string, items: list<array{sort: int, service_id: int, service_name: string, staff_id: int, staff_name: string|null, start: string, end: string, price_minor: int}>}
     */
    public static function guest(BookingSnapshot $booking): array
    {
        return [
            'uuid' => $booking->uuid,
            'status' => $booking->status->value,
            'hold_class' => $booking->holdClass?->value,
            'hold_expires_at' => $booking->holdExpiresAt === null ? null : self::instant($booking->holdExpiresAt),
            'customer_name' => $booking->customer?->name,
            'customer_email' => $booking->customer?->email,
            'customer_phone' => $booking->customer?->phone,
            'total_minor' => $booking->total->minor,
            'currency' => $booking->total->currency->code,
            'items' => array_map(self::item(...), $booking->items),
        ];
    }

    /**
     * The admin's view, for the bookings list, the booking dialog and the two calendars alike: the
     * guest's columns and the viewer's, less the customer's email and phone unless the viewer holds
     * manageBookings, and with manageBookings every operational column besides, the booking's and
     * each item's. The same buckets decide it as decide every other surface, so the admin has no
     * second idea of what a booking looks like on the wire.
     *
     * One clause reaches past manageBookings: an item on the viewer's own staff row, the row whose
     * user_id is theirs, carries its operational columns for them too, because a member's own
     * buffers and processing gap are the tenant's arrangement and the member is the tenant's side
     * of it, and the Calendar pinned to their own row draws their day from those ranges. An item on
     * anybody else's row stays the guest's shape for them, and the customer's email and phone are
     * withheld whatever row the item is on.
     *
     * @param  int|null  $ownStaff  the viewer's own staff row, or null where they have none
     * @return array<string, mixed>
     */
    public static function admin(BookingRecord $record, bool $manageBookings, ?int $ownStaff = null): array
    {
        $guest = self::guest($record->snapshot);
        $viewer = self::only($record->operations, self::VIEWER);
        $items = [];

        foreach ($guest['items'] as $index => $item) {
            $items[] = $manageBookings || ($ownStaff !== null && $item['staff_id'] === $ownStaff)
                ? [...$item, ...self::only($record->itemOperations[$index] ?? [], self::ITEM_MANAGE_ONLY)]
                : $item;
        }

        if (! $manageBookings) {
            return [...array_diff_key($guest, array_flip(self::CONTACT)), ...$viewer, 'items' => $items];
        }

        return [...$guest, ...$viewer, ...self::only($record->operations, self::MANAGE_ONLY), 'items' => $items];
    }

    /**
     * The facts of one audit entry as the trail shows them: the whitelisted keys, as they were
     * written, and nothing else.
     *
     * @param  array<mixed>  $facts
     * @return array<string, mixed>
     */
    public static function trailFacts(array $facts): array
    {
        return array_intersect_key($facts, array_flip(self::TRAIL_FACTS));
    }

    /**
     * The customer columns the bookings list may search for this viewer: the searched ones the
     * admin's view shows them, so a search can never confirm a value the row would withhold. A
     * viewer without manageBookings searches the name alone.
     *
     * @return list<string>
     */
    public static function searchable(bool $manageBookings): array
    {
        return array_values(array_diff(self::SEARCHED, $manageBookings ? [] : self::CONTACT));
    }

    /**
     * @param  array<string, string|int|bool|null>  $columns
     * @param  list<string>  $names
     * @return array<string, string|int|bool|null>
     */
    private static function only(array $columns, array $names): array
    {
        $picked = [];

        foreach ($names as $name) {
            $picked[$name] = $columns[$name] ?? null;
        }

        return $picked;
    }

    /**
     * The answer to POST /holds: the guest view, and the manage token in plaintext beside it.
     * The token appears here exactly once in its life and can never be fetched again, because
     * only its hash was stored.
     *
     * checkout_url is present only for a hold that cannot be confirmed until it is paid, at a
     * tenant whose payment provider is a working one, and is then the pay hop carrying the
     * token; every other hold carries no key at all. The booking app branches on the key's
     * presence, so an absent key is the whole answer and a null would be a third state to reason
     * about.
     *
     * pays_at_venue is true, and present only then, on a hold of an online booking that no
     * working provider can take the money for: the confirm settles it unpaid and the done step
     * tells the customer they pay at the venue. It never rides beside checkout_url.
     *
     * @return array<string, mixed>
     */
    public static function hold(BookingSnapshot $booking, string $manageToken, ?string $checkoutUrl = null, bool $paysAtVenue = false): array
    {
        return [
            ...self::guest($booking),
            'manage_token' => $manageToken,
            ...($checkoutUrl === null ? [] : ['checkout_url' => $checkoutUrl]),
            ...($checkoutUrl === null && $paysAtVenue ? ['pays_at_venue' => true] : []),
        ];
    }

    /**
     * One item as a guest sees it. The staff member is named only where they are a person the
     * customer could have picked: the implicit member stands for the business itself, so their
     * name is the tenant's and saying it would offer the business as a second stylist.
     *
     * @return array{sort: int, service_id: int, service_name: string, staff_id: int, staff_name: string|null, start: string, end: string, price_minor: int}
     */
    private static function item(BookingItemSnapshot $item): array
    {
        return [
            'sort' => $item->sort,
            'service_id' => $item->serviceId,
            'service_name' => $item->serviceName,
            'staff_id' => $item->staffId,
            'staff_name' => $item->staffIsImplicit ? null : $item->staffName,
            'start' => self::instant($item->start),
            'end' => self::instant($item->end),
            'price_minor' => $item->price->minor,
        ];
    }

    /**
     * An instant as this API spells one: RFC 3339 in UTC, the offset written out rather than a
     * Z, the same spelling the availability answer uses and the client parses. The one spelling
     * of it: every payload and every reader that hands the admin or the booking app an instant
     * writes it through here.
     */
    public static function instant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339);
    }
}
