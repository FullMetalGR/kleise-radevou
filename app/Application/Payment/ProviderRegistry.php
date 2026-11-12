<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * The provider registry: one descriptor per provider, and the one place a connection is turned
 * into the port over it. Adding a provider is a registration here and nothing anywhere else.
 *
 * A descriptor is checked when it is registered and not when it is read, so one that leaves a
 * field out fails where the provider is added rather than on the screen or the payment that
 * first needed the field.
 */
final class ProviderRegistry
{
    /**
     * The slugs no provider may take: POST /webhooks/billing is the platform's own billing
     * webhook, registered before /webhooks/{provider} (routes/central.php), so a provider slugged
     * billing would have its platform webhook shadowed.
     */
    public const array RESERVED = ['billing'];

    /** @var array<string, PaymentAdapter> */
    private array $adapters = [];

    /** @var array<string, ProviderDescriptor> */
    private array $descriptors = [];

    /**
     * @throws RegistrationRefused when the descriptor is incomplete or its slug is taken or reserved
     */
    public function register(PaymentAdapter $adapter): void
    {
        $descriptor = $adapter->descriptor();
        $slug = $descriptor->slug;

        if (isset($this->descriptors[$slug])) {
            throw RegistrationRefused::taken($slug);
        }

        if (in_array($slug, self::RESERVED, true)) {
            throw RegistrationRefused::reserved($slug);
        }

        foreach (self::missing($descriptor) as $field) {
            throw RegistrationRefused::missing($slug, $field);
        }

        $this->adapters[$slug] = $adapter;
        $this->descriptors[$slug] = $descriptor;
    }

    /**
     * Every registered provider's descriptor, in the order they were registered.
     *
     * @return list<ProviderDescriptor>
     */
    public function descriptors(): array
    {
        return array_values($this->descriptors);
    }

    public function descriptor(string $slug): ?ProviderDescriptor
    {
        return $this->descriptors[$slug] ?? null;
    }

    /**
     * The adapter registered under a slug, or null for one this build does not register. The
     * payment webhook asks it for the provider named in the path, so a notification is verified
     * by the adapter of its own provider and by no other.
     */
    public function adapter(string $slug): ?PaymentAdapter
    {
        return $this->adapters[$slug] ?? null;
    }

    /**
     * The port over a connection: its provider's adapter, or the null provider for a row naming
     * a provider this build does not register or a mode that provider does not declare. A row
     * this build cannot read degrades to pay-on-site as a missing one does; it never throws
     * into a booking. The store is the tenant's, the door the port borrows credentials through.
     */
    public function provider(PaymentConnection $connection, PaymentConnections $connections): PaymentProvider
    {
        $adapter = $this->adapters[$connection->provider] ?? null;
        $descriptor = $this->descriptors[$connection->provider] ?? null;

        if ($adapter === null || $descriptor === null || ! $descriptor->offers($connection->mode)) {
            return new NullPaymentProvider;
        }

        return $adapter->provider($connection, $connections);
    }

    /**
     * The declared fields this descriptor leaves out, by name. Own mode is nothing without its
     * fields, platform mode nothing without a way in, and a platform mode with no fee mechanism
     * is not offered rather than offered and quietly free. The published rate is an https
     * address, since the Payments section links it as it stands.
     *
     * @return list<string>
     */
    private static function missing(ProviderDescriptor $descriptor): array
    {
        $missing = [];

        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $descriptor->slug) !== 1) {
            $missing[] = 'slug';
        }

        if (trim($descriptor->name) === '') {
            $missing[] = 'name';
        }

        if ($descriptor->modes === [] || count(array_unique(array_column($descriptor->modes, 'value'))) !== count($descriptor->modes)) {
            $missing[] = 'modes';
        }

        if (! self::fieldsDeclared($descriptor)) {
            $missing[] = 'credentialFields';
        }

        if ($descriptor->offers(ConnectionMode::Platform) && $descriptor->onboardingEntry === null) {
            $missing[] = 'onboardingEntry';
        }

        if ($descriptor->offers(ConnectionMode::Platform) && $descriptor->feeMechanism === FeeMechanism::None) {
            $missing[] = 'feeMechanism';
        }

        if (trim($descriptor->webhookSignature->header) === '') {
            $missing[] = 'webhookSignature';
        }

        if ($descriptor->currencies === [] || array_filter($descriptor->currencies, fn (string $code): bool => preg_match('/\A[A-Z]{3}\z/', $code) !== 1) !== []) {
            $missing[] = 'currencies';
        }

        if (! self::secureAddress($descriptor->rateUrl)) {
            $missing[] = 'rateUrl';
        }

        if ($descriptor->canExpireCheckout === null) {
            $missing[] = 'canExpireCheckout';
        }

        return $missing;
    }

    /**
     * Own mode declares at least one field; every field has a name, a label and help, and no
     * two share a name.
     */
    private static function fieldsDeclared(ProviderDescriptor $descriptor): bool
    {
        $names = [];

        foreach ($descriptor->credentialFields as $field) {
            if (trim($field->name) === '' || trim($field->label) === '' || trim($field->help) === '' || in_array($field->name, $names, true)) {
                return false;
            }

            $names[] = $field->name;
        }

        return $names !== [] || ! $descriptor->offers(ConnectionMode::Own);
    }

    /** An absolute https address with a host, and nothing else a link could be handed. */
    private static function secureAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_URL) !== false
            && parse_url($address, PHP_URL_SCHEME) === 'https'
            && is_string(parse_url($address, PHP_URL_HOST));
    }
}
