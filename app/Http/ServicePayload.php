<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\CatalogService;
use App\Application\Dto\CatalogStaff;
use App\Domain\Chain\Chain;

/**
 * What a customer may see of a service, on every surface: the one class that decides. It is a
 * whitelist. Every column of the services table is named here as published or withheld, and a
 * test reads the live table and fails on a column this class does not classify, so a column
 * added later is invisible until somebody decides.
 *
 * The price travels as an integer of minor units beside its currency, never as a formatted
 * string: the booking app formats each row in the currency the row carries.
 */
final readonly class ServicePayload
{
    /**
     * The columns that reach the wire, under their own names. The category is the heading the
     * booking page groups a row under; online is published because the public catalogue
     * answers it, true on every row it lists, and the admin reads the same column to mark a
     * row Online or Not online.
     */
    public const array PUBLISHED = ['id', 'name', 'description', 'duration_min', 'price_minor', 'currency', 'category', 'online'];

    /**
     * The columns that never do: the payment mode, the approval rules, the buffers and gap,
     * the windows and limits, the status, the sort and the timestamps. The engine reads them;
     * a customer learns their effect from the availability answer and the hold, not from the
     * catalog.
     */
    public const array WITHHELD = [
        'processing_gap_min', 'buffer_before_min', 'buffer_after_min', 'payment_mode',
        'requires_approval', 'approval_hold_hours', 'on_approval_timeout', 'cancel_window_hours',
        'reschedule_window_hours', 'lead_time_min', 'horizon_days', 'status', 'sort',
        'created_at', 'updated_at',
    ];

    /**
     * The columns of the staff table a customer meets in a service's list of performers, under
     * their own names, and photo_path, which reaches the wire as photo_url, the URL the file is
     * served at on the tenant's own origin, never as the stored path. The catalogue lists only
     * members active, offered online and not implicit, so those three columns decide who is
     * listed and say nothing about the member listed.
     */
    public const array STAFF_PUBLISHED = ['id', 'name', 'photo_path', 'title', 'bio'];

    /**
     * The staff columns that never reach a customer: the member's own address, the linked user,
     * the flags the catalogue filters on, the sort, the admin calendar's colour and the timestamps.
     */
    public const array STAFF_WITHHELD = ['user_id', 'email', 'status', 'is_implicit', 'online', 'sort', 'colour', 'created_at', 'updated_at'];

    /**
     * The columns the owner reads on the Services screen, under their own names: everything
     * published to the customer plus every configuration column the detail page edits. The
     * sort and the timestamps stay out; the list's order is the sort's effect and nothing on
     * the screen reads a timestamp. Every column here is published or withheld above, so the
     * customer's decision stays the whole classification.
     */
    public const array ADMIN = [
        'id', 'name', 'description', 'duration_min', 'processing_gap_min', 'buffer_before_min',
        'buffer_after_min', 'price_minor', 'currency', 'payment_mode', 'requires_approval',
        'approval_hold_hours', 'on_approval_timeout', 'cancel_window_hours',
        'reschedule_window_hours', 'lead_time_min', 'horizon_days', 'status', 'category', 'online',
    ];

    /**
     * The admin columns that are counts, read back as integers whatever the driver's width.
     */
    public const array ADMIN_COUNTS = [
        'id', 'duration_min', 'processing_gap_min', 'buffer_before_min', 'buffer_after_min',
        'price_minor', 'approval_hold_hours', 'cancel_window_hours', 'reschedule_window_hours',
        'lead_time_min', 'horizon_days',
    ];

    /**
     * GET /services: the list, and the server's cap on segments in one chain beside it, so the
     * booking app's add button and the server refuse at the same number.
     *
     * @param  list<CatalogService>  $services
     * @return array{services: list<array{id: int, name: string, description: string, duration_min: int, price_minor: int, currency: string, category: ?string, online: bool, staff: list<array{id: int, name: string, photo_url: string|null, title: string|null, bio: string|null}>}>, max_chain_length: int}
     */
    public static function catalog(array $services): array
    {
        return [
            'services' => array_map(self::of(...), $services),
            'max_chain_length' => Chain::MAX_SEGMENTS,
        ];
    }

    /**
     * @return array{id: int, name: string, description: string, duration_min: int, price_minor: int, currency: string, category: ?string, online: bool, staff: list<array{id: int, name: string, photo_url: string|null, title: string|null, bio: string|null}>}
     */
    public static function of(CatalogService $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'description' => $service->description,
            'duration_min' => $service->durationMin,
            'price_minor' => $service->price->minor,
            'currency' => $service->price->currency->code,
            'category' => $service->category,
            'online' => $service->online,
            'staff' => array_map(
                fn (CatalogStaff $staff): array => ['id' => $staff->id, 'name' => $staff->name, 'photo_url' => $staff->photoUrl, 'title' => $staff->title, 'bio' => $staff->bio],
                $service->staff,
            ),
        ];
    }
}
