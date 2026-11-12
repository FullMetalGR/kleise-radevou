<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * What one provider's directory hands the registry: its descriptor, and the port over one of a
 * tenant's connections to it. A provider is a plugin, and this is the whole of the socket.
 */
interface PaymentAdapter
{
    public function descriptor(): ProviderDescriptor;

    /**
     * The port over this connection. Asked only for a connection that works, and it calls no
     * provider to answer. The store is the tenant's, and its one door, withCredentials, is how
     * the port borrows the connection's credentials for a call that leaves the process; neither
     * building the port nor its isAvailable may open it, since both run inside the booking
     * transaction under the staff-day lock.
     */
    public function provider(PaymentConnection $connection, PaymentConnections $connections): PaymentProvider;

    /**
     * A notification this provider sent, verified by the scheme its descriptor declares with the
     * connection's own secret and read as a notice. Fails closed: a signature that does not verify,
     * and a verified body that cannot be a notification, are refused and nothing is read from them.
     * Calls no provider, and is asked before any booking transaction opens, the credentials lent
     * for this call alone.
     *
     * @param  string  $body  the raw body, the signed bytes
     * @param  string  $signature  the value of the header the descriptor names, empty when absent
     *
     * @throws NotificationRefused when the notification is not one this connection's provider signed
     */
    public function verify(string $body, string $signature, Credentials $credentials): PaymentNotice;

    /**
     * One cheap authenticated call with these credentials, for the connection test: passed, or
     * failed with the provider's reason and no secret. Never throws.
     */
    public function check(Credentials $credentials): ConnectionCheck;

    /**
     * Opens the provider's connected account for a tenant connecting through us, with the
     * platform's own keys, and answers its reference. Platform mode only; called outside any
     * transaction, and a failure throws.
     */
    public function openAccount(Credentials $platform): string;

    /**
     * A fresh link into the provider's hosted onboarding for one connected account, returning
     * the owner to the address given. Platform mode only; a failure throws.
     */
    public function onboardingLink(Credentials $platform, string $accountRef, string $returnUrl): string;

    /**
     * Verifies a notification on the platform's endpoint with the platform's own signing
     * secret, and reads it: the account it concerns and, for an account update, where that
     * account stands.
     *
     * @throws NotificationRefused when the signature or the body cannot be vouched for
     */
    public function verifyPlatform(string $body, string $signature, Credentials $platform): PlatformNotice;
}
