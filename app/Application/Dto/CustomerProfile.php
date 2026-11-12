<?php

declare(strict_types=1);

namespace App\Application\Dto;

use DateTimeImmutable;

/**
 * What the business keeps about a customer beside the bookings: the row customer_profiles holds
 * for one address, or none where nothing was ever saved, which reads as no tags, no birthday, no
 * language and not blocked. The tags each once, in the order they were written; the birthday a
 * calendar date, YYYY-MM-DD; the language en or el; blocked whether online booking is refused
 * for the address, and with a block the instant it was made and the reason the staff gave, null
 * where none was given. Which of these reach a viewer is App\Http\CustomerPayload's decision and
 * the customer's page's.
 */
final readonly class CustomerProfile
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public array $tags,
        public ?string $birthday,
        public ?string $language,
        public bool $blocked,
        public ?DateTimeImmutable $blockedAt = null,
        public ?string $blockedReason = null,
    ) {}

    /** An address with no profile row. */
    public static function none(): self
    {
        return new self([], null, null, false);
    }
}
