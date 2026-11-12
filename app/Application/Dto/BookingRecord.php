<?php

declare(strict_types=1);

namespace App\Application\Dto;

/**
 * A booking as an operator's screen reads it: the snapshot every surface is built from, and
 * beside it the operational columns of the booking and of each item, already in their wire
 * spelling and keyed by column name. Which of them reach a viewer is not this class's decision
 * but the payload whitelist's, App\Http\BookingPayload::admin.
 *
 * It carries one fact that is not a column: whether the hold is live, the database's own answer
 * to hold_expires_at against now() in the statement that read the row. The guards of approve and
 * reject ask the same question of the re-read under the lock, and the admin's list of the actions
 * allowed now asks it of this read, so neither asks a clock of ours.
 *
 * @phpstan-type Operational array<string, string|int|bool|null>
 */
final readonly class BookingRecord
{
    /**
     * @param  array<string, string|int|bool|null>  $operations  the booking's columns beyond the snapshot
     * @param  list<array<string, string|int|bool|null>>  $itemOperations  each item's, in chain order
     * @param  bool  $holdIsLive  the server's answer to hold_expires_at > now(), false where there is no expiry
     */
    public function __construct(
        public BookingSnapshot $snapshot,
        public array $operations,
        public array $itemOperations,
        public bool $holdIsLive,
    ) {}
}
