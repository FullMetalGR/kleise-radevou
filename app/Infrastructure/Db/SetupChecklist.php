<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;

/**
 * The four facts Home's setup checklist is drawn from: whether a service exists, whether the
 * opening hours were ever confirmed, whether the booking link was ever copied, and whether a
 * booking exists in any status. Four reads and no table: nothing records that the card was shown,
 * and the markers are never cleared and a booking's items hold their services against deletion, so
 * a card gone stays gone.
 */
final readonly class SetupChecklist
{
    public function __construct(private DatabaseManager $db, private SettingsReader $settings) {}

    /**
     * @return array{service: bool, hours: bool, link: bool, booking: bool}
     */
    public function facts(Tenant $tenant): array
    {
        $settings = $this->settings->read($tenant);

        return [
            'service' => $this->db->connection()->table($tenant->schema_name.'.services')->exists(),
            'hours' => $settings->hoursConfirmed,
            'link' => $settings->linkCopied,
            'booking' => $this->db->connection()->table($tenant->schema_name.'.bookings')->exists(),
        ];
    }
}
