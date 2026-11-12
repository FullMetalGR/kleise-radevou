<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Application\Notification\MessageKey;
use InvalidArgumentException;

/**
 * A tenant's settings row, the columns of it read here: the slot granularity the boot config
 * carries, the start interval the customer's availability reads offer starts on, the week start
 * and the two formats the admin's shared props carry, the two hold TTLs the hold copies onto a
 * booking, the checkout minutes and the approval hours a service without a window of its own
 * falls back to, the reminder lead, and the message keys the tenant has switched off. The
 * remaining columns are not read here.
 *
 * The start interval is how often a customer is offered a start: it divides the hour, so the
 * same starts fall in every hour of every day, and it is a multiple of the granularity, so every
 * start it offers lies on the grid the engine books against. It shapes the offer and nothing
 * else; the engine's grid stays the granularity. The two rules are the CHECK on the column as
 * well, and are refused here too so that no Settings exists that the row could not hold.
 *
 * The reminder lead is the one number here that zero is a value of. The three holds must be
 * positive, because a hold that lives no time is a hold nobody can settle; the lead is how many
 * hours before an appointment the customer is reminded, and zero means they are not, which is
 * why it has its own rule, so that it cannot inherit the holds' one. Nothing here reads a lead
 * as "on" or "off": the scheduler asks the number.
 *
 * emails_off is stored as the negative and is read as one: a key on the list is a message this
 * tenant does not want, and a key absent from it sends. That is what makes a message added in a
 * later release send by default rather than silently not, and it is why this list is never
 * inverted anywhere - the Emails section ticks a checkbox when the key is absent, and the seam
 * refuses to send when it is present.
 *
 * One key is held on whatever the list says: the approved message while the tenant takes
 * payments, a working payment connection read beside the row, because its awaiting-payment form
 * carries the customer's only link to the payment. The send ignores the key on the list and a
 * save stores the list without it, so a tenant cannot cut the road to their own money; with no
 * working connection the key is switchable like every other. The hold lives here, in the one
 * reading of the list, so the seam, the notice that says whether the customer heard and the
 * Emails section all meet it once.
 *
 * The two setup markers are read as whether and never when: the setup checklist asks whether the
 * opening hours were ever confirmed and whether the booking link was ever copied, so the
 * instants stay in the row and no instant is parsed to answer it. Neither is ever cleared, so
 * neither turns false again.
 *
 * The cover and the gallery are the business page's two pictures, paths the tenant image store
 * answers, the gallery in the order its photos were added and never more than its capacity.
 */
final readonly class Settings
{
    /** The photos the gallery holds at most: the column's CHECK and the upload's refusal of the next. */
    public const int GALLERY_CAPACITY = 8;

    /** The date format every tenant is provisioned with, the column's default: the day, the month, the year. */
    public const string DATE_FORMAT = 'd/m/Y';

    /** The time format every tenant is provisioned with, the column's default: the 24-hour clock. */
    public const string TIME_FORMAT = 'H:i';

    /**
     * The words settings.colour_by holds, its CHECK's list: each block coloured by its staff
     * member, its service's category or its status.
     *
     * @var list<string>
     */
    public const array COLOUR_BY = ['staff', 'category', 'status'];

    /**
     * @param  int  $reminderLeadHours  how many hours before an appointment the customer is reminded, zero for never
     * @param  list<string>  $emailsOff  the message keys this tenant has switched off
     * @param  int  $startOfWeek  the weekday a week starts on, ISO numbered, 1 for Monday
     * @param  string  $dateFormat  PHP date tokens, fixed at provisioning
     * @param  string  $timeFormat  PHP time tokens, fixed at provisioning
     * @param  int  $paymentTtlHours  how long an emailed payment link stays open
     * @param  string|null  $brandLogoPath  the logo's path on the public disk, null for none; the hosted page's header alone renders it
     * @param  int  $startIntervalMin  how often a customer is offered a start, in minutes: dividing sixty and a multiple of the granularity
     * @param  bool  $hoursConfirmed  whether the opening hours were ever confirmed, by a save of a week holding a window, their own or a staff save's; an empty week confirms nothing
     * @param  bool  $linkCopied  whether the booking link was ever copied from the admin
     * @param  Profile  $profile  the business page's text and Important info, each field null where empty
     * @param  string|null  $coverPath  the business page's cover on the public disk, null for none
     * @param  list<string>  $galleryPaths  the business page's gallery on the public disk, in the order the photos were added, at most GALLERY_CAPACITY
     * @param  float|null  $lat  the latitude the address was last geocoded to, null before any and once a failed or a none write dropped the map
     * @param  float|null  $lng  the longitude beside it
     * @param  string|null  $mapPath  the stored map image's path on the public disk, null before any and once a failed or a none write dropped the map
     * @param  MapState  $mapState  where the map stands; the page draws the pair and the image on ready alone
     * @param  bool  $takesPayments  whether a working payment connection exists, PaymentConnection::working over the primary row's state and stored check, read with this row
     * @param  string  $colourBy  how every calendar grid colours its blocks, one of COLOUR_BY
     */
    public function __construct(
        public int $granularityMin,
        public int $checkoutTtlMin,
        public int $approvalTtlHours,
        public int $reminderLeadHours,
        public array $emailsOff = [],
        public int $startOfWeek = 1,
        public string $dateFormat = self::DATE_FORMAT,
        public string $timeFormat = self::TIME_FORMAT,
        public int $paymentTtlHours = 24,
        public ?string $brandLogoPath = null,
        public int $startIntervalMin = 15,
        public bool $hoursConfirmed = false,
        public bool $linkCopied = false,
        public Profile $profile = new Profile,
        public ?string $coverPath = null,
        public array $galleryPaths = [],
        public ?float $lat = null,
        public ?float $lng = null,
        public ?string $mapPath = null,
        public MapState $mapState = MapState::None,
        public bool $takesPayments = false,
        public string $colourBy = 'staff',
    ) {
        if (! in_array($colourBy, self::COLOUR_BY, true)) {
            throw new InvalidArgumentException(sprintf('Colour by is staff, category or status, not "%s".', $colourBy));
        }

        if ($checkoutTtlMin < 1) {
            throw new InvalidArgumentException(sprintf('A checkout TTL is at least one minute, not %d.', $checkoutTtlMin));
        }

        if ($approvalTtlHours < 1) {
            throw new InvalidArgumentException(sprintf('An approval TTL is at least one hour, not %d.', $approvalTtlHours));
        }

        if ($paymentTtlHours < 1) {
            throw new InvalidArgumentException(sprintf('A payment TTL is at least one hour, not %d.', $paymentTtlHours));
        }

        if ($reminderLeadHours < 0) {
            throw new InvalidArgumentException(sprintf('A reminder lead is zero or a number of hours above it, not %d.', $reminderLeadHours));
        }

        if ($startIntervalMin < 1 || 60 % $startIntervalMin !== 0) {
            throw new InvalidArgumentException(sprintf('A start interval is a number of minutes dividing sixty, not %d.', $startIntervalMin));
        }

        // The granularity is not judged here, Granularity::minutes refuses a bad one where the
        // engine reads it; the guard only keeps a zero from being divided by.
        if ($granularityMin > 0 && $startIntervalMin % $granularityMin !== 0) {
            throw new InvalidArgumentException(sprintf('A start interval is a multiple of the %d-minute granularity, not %d.', $granularityMin, $startIntervalMin));
        }
    }

    /**
     * Whether the tenant sends messages of this key: the key is absent from emails_off. The one
     * reading of the list, which the seam asks before it sends and every surface asks before it
     * offers what a message would carry, the business page's way to My bookings and the bookings
     * link's request among them, so no reader acquires a second idea of what was switched off.
     */
    public function sends(MessageKey $key): bool
    {
        return $this->locked($key) || ! in_array($key->value, $this->emailsOff, true);
    }

    /**
     * Whether this key is held on whatever emails_off holds: the approved message while the
     * tenant takes payments. The Emails section renders it checked and disabled, the send
     * ignores it on the list, and a save stores the list without it.
     */
    public function locked(MessageKey $key): bool
    {
        return $key === MessageKey::Approved && $this->takesPayments;
    }

    /**
     * The off list a save stores from the keys it was given: each once, in the order given, a
     * key this build does not know kept, and no key held on, so a save listing the locked key
     * off is ignored for that key and the stored list never says what the send would not do.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function storable(array $keys): array
    {
        return array_values(array_unique(array_filter($keys, function (string $value): bool {
            $key = MessageKey::tryFrom($value);

            return $key === null || ! $this->locked($key);
        })));
    }
}
