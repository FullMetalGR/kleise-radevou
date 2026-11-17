<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\Intake;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * The customer's note on a booking, booking_meta's key note: the one reader of it, for the
 * business's surfaces alone, the booking dialog and the business's new-booking and
 * approval-request messages.
 *
 * It is read on its own rather than carried on the snapshot, the way the token hash is, so that
 * the object every payload and every customer message is built from cannot be carrying it: the
 * note is the customer's words to the business and is never echoed back to them, and the guest
 * view, the trail and the customer's eight messages never see it. One statement, null where the
 * booking has no note or no booking has the uuid.
 *
 * Every table is named by schema and nothing is set on the connection, the way the booking
 * reader honours the pooling rule.
 */
final readonly class BookingNotes
{
    public function __construct(private DatabaseManager $db) {}

    public function of(Tenant $tenant, string $uuid): ?string
    {
        if (! SqlText::isUuid($uuid)) {
            return null;
        }

        $note = $this->db->connection()
            ->table($tenant->schema_name.'.booking_meta', 'meta')
            ->join($tenant->schema_name.'.bookings as booking', 'booking.id', '=', 'meta.booking_id')
            ->where('booking.uuid', $uuid)
            ->where('meta.key', Intake::NOTE_KEY)
            ->value('meta.value');

        if ($note !== null && ! is_string($note)) {
            throw new LogicException(sprintf('A booking_meta row of tenant "%s" is not shaped as the schema declares.', $tenant->slug));
        }

        return $note;
    }

    /**
     * Which of the bookings carry a note, by uuid, never the words: one statement for a whole
     * window of bookings, their uuids bound as one array however many they are, the calendar's
     * mark that a note is there to read in the dialog.
     *
     * @param  list<string>  $uuids
     * @return list<string>
     */
    public function notedAmong(Tenant $tenant, array $uuids): array
    {
        $uuids = array_values(array_filter($uuids, SqlText::isUuid(...)));

        if ($uuids === []) {
            return [];
        }

        return array_values($this->db->connection()
            ->table($tenant->schema_name.'.booking_meta', 'meta')
            ->join($tenant->schema_name.'.bookings as booking', 'booking.id', '=', 'meta.booking_id')
            ->whereRaw('booking.uuid = any(?::uuid[])', [TextArray::encode($uuids)])
            ->where('meta.key', Intake::NOTE_KEY)
            ->pluck('booking.uuid')
            ->map(fn (mixed $uuid): string => is_string($uuid) ? $uuid : throw new LogicException(sprintf('A booking of tenant "%s" is not shaped as the schema declares.', $tenant->slug)))
            ->all());
    }
}
