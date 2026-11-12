<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use Illuminate\Database\DatabaseManager;

/**
 * The central routing rows, payment_routes, read: which tenant a notification arriving on the
 * central host is for. Own mode names its connection by the token in the path; platform mode
 * reads the same table by the connected account. Written by the connection store in the
 * connection's own transaction, never here.
 */
final readonly class PaymentRoutes
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * The tenant id an own-mode token names for a provider, or null for a token no connection
     * of that provider holds.
     */
    public function byToken(string $provider, string $token): ?int
    {
        $tenant = $this->db->connection()->scalar(
            'select tenant_id from public.payment_routes where provider = ? and webhook_token = ?',
            [$provider, $token],
        );

        return is_int($tenant) ? $tenant : null;
    }

    /**
     * The tenant whose platform connection holds a provider's connected account, or null.
     */
    public function byAccount(string $provider, string $accountRef): ?int
    {
        $tenant = $this->db->connection()->scalar(
            'select tenant_id from public.payment_routes where provider = ? and account_ref = ?',
            [$provider, $accountRef],
        );

        return is_int($tenant) ? $tenant : null;
    }
}
