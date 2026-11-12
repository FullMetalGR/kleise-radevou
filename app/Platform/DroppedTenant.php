<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * What the drop removed of one tenant: the tenant's id and the schema it dropped, and how many
 * rows went from each central table the tenant's rows sat in. Counts and names alone, which is
 * all the sweep logs of it.
 */
final readonly class DroppedTenant
{
    public function __construct(
        public int $tenantId,
        public string $schema,
        public int $users,
        public int $paymentRoutes,
        public int $supportSessions,
        public int $webhookEvents,
    ) {}

    /**
     * The counts by the table each was deleted from, as the sweep's line logs them.
     *
     * @return array{users: int, payment_routes: int, support_sessions: int, webhook_events: int}
     */
    public function counts(): array
    {
        return [
            'users' => $this->users,
            'payment_routes' => $this->paymentRoutes,
            'support_sessions' => $this->supportSessions,
            'webhook_events' => $this->webhookEvents,
        ];
    }
}
