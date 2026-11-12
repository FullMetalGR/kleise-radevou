<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use Illuminate\Database\DatabaseManager;

/**
 * The tenant payments' own record of the notifications they received, payment_events. Billing's
 * webhook_events is billing's: nothing here reads or writes it.
 *
 * A verified notification is recorded by its provider and event id before it is handled and
 * marked once handling completes; a repeat finds the row, and a marked row is the no-op. A
 * refused one is recorded with why and nothing of its body.
 */
final readonly class PaymentEvents
{
    public const string UNKNOWN_PROVIDER = 'unknown_provider';

    public const string UNKNOWN_ROUTE = 'unknown_route';

    public const string SIGNATURE = 'signature';

    public const string OWN = 'own';

    public const string PLATFORM = 'platform';

    public function __construct(private DatabaseManager $db) {}

    /**
     * A notification refused before anything of it was read: the provider, the route it came in
     * on, the tenant the route named where it named one, and why. When is the row's own.
     */
    public function refused(string $provider, string $route, ?int $tenant, string $refusal): void
    {
        $this->db->connection()->insert(
            'insert into public.payment_events (provider, route, tenant_id, refusal) values (?, ?, ?, ?)',
            [$provider, $route, $tenant, $refusal],
        );
    }

    /**
     * Records a verified notification once, and answers whether it is still to be handled: true
     * for one never seen and for one recorded by an attempt that did not finish, false for one
     * already marked.
     */
    public function stillToHandle(string $provider, string $route, int $tenant, string $eventId, string $type): bool
    {
        $db = $this->db->connection();

        $db->statement(
            'insert into public.payment_events (provider, route, tenant_id, event_id, type) values (?, ?, ?, ?, ?)'
            .' on conflict (provider, event_id) do nothing',
            [$provider, $route, $tenant, $eventId, $type],
        );

        $processed = $db->selectOne(
            'select processed_at is not null as processed from public.payment_events where provider = ? and event_id = ?',
            [$provider, $eventId],
        );

        return ! (is_object($processed) && ((array) $processed)['processed'] === true);
    }

    /**
     * Marks a verified notification handled, and answers whether this call marked it: false
     * where a concurrent delivery of the same event marked it first, so whatever follows the
     * mark happens once per event.
     */
    public function mark(string $provider, string $eventId): bool
    {
        return $this->db->connection()->update(
            'update public.payment_events set processed_at = now(), updated_at = now()'
            .' where provider = ? and event_id = ? and processed_at is null',
            [$provider, $eventId],
        ) === 1;
    }
}
