<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\Payment\CheckoutHop;
use App\Application\Payment\ManageTokenSeal;
use App\Application\Payment\PaymentConnections;
use App\Application\Payment\PaymentProvider;
use App\Application\Payment\ProviderRegistry;
use App\Application\Payment\TenantPaymentProvider;
use App\Infrastructure\Db\LockManager;
use App\Infrastructure\Db\PostgresCheckoutRecords;
use App\Infrastructure\Db\PostgresPaymentConnections;
use App\Infrastructure\Db\TransactionRunner;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;

/**
 * The one place the payment port is built for a tenant: its connection store and the provider
 * that answers for it, both bound to the one tenant given, so a store and a provider for
 * different tenants can never be paired. The registry is the application's one.
 */
final readonly class PaymentFactory
{
    public function __construct(
        private DatabaseManager $db,
        private StringEncrypter $encrypter,
        private ProviderRegistry $registry,
        private TransactionRunner $runner,
        private LockManager $locks,
    ) {}

    public function connections(Tenant $tenant): PaymentConnections
    {
        return new PostgresPaymentConnections($this->db, $this->encrypter, $tenant);
    }

    /**
     * The tenant's provider: its primary connection's adapter while that row works, the null
     * provider otherwise, decided from the row each time it is asked.
     */
    public function provider(Tenant $tenant): PaymentProvider
    {
        return new TenantPaymentProvider($this->connections($tenant), $this->registry);
    }

    /**
     * The pay hop for a tenant: its booking rows and the provider answering for it.
     */
    public function hop(Tenant $tenant): CheckoutHop
    {
        return new CheckoutHop(new PostgresCheckoutRecords($this->db, $tenant, $this->seal(), $this->runner, $this->locks), $this->provider($tenant));
    }

    /**
     * How the manage token is sealed onto a booking by the hop and opened by the paid
     * transition.
     */
    public function seal(): ManageTokenSeal
    {
        return new EncryptedTokenSeal($this->encrypter);
    }
}
