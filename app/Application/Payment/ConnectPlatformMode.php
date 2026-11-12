<?php

declare(strict_types=1);

namespace App\Application\Payment;

use LogicException;

/**
 * Platform mode's one button: the provider's connected account is opened with the platform's
 * keys, the connection written pending with the account's reference and its routing row, and
 * the owner sent to the provider's hosted onboarding. The provider's own callback moves the row
 * on from there.
 *
 * The account is opened before anything is written and the link asked for after the write
 * commits, so a provider failing at the first leaves nothing and at the second leaves a pending
 * connection the section shows, resumes and removes. Pressing it again for the provider already
 * connected through us opens a fresh link for the same account and opens no second one. A
 * provider connected with the tenant's own credentials refuses it until it is disconnected.
 */
final readonly class ConnectPlatformMode
{
    public function __construct(
        private PlatformMode $platform,
        private ProviderRegistry $registry,
    ) {}

    /**
     * @return string the provider's hosted onboarding, to send the owner to
     *
     * @throws ConnectionModeTaken where the provider is connected with the tenant's own credentials
     */
    public function connect(PaymentConnections $connections, PaymentAdapter $adapter, string $returnUrl): string
    {
        $descriptor = $adapter->descriptor();
        $keys = $this->platform->keys($descriptor->slug) ?? throw new LogicException('Platform mode was validated as offered and its keys are unset.');
        $current = $connections->primary();
        $same = $current !== null && $current->provider === $descriptor->slug;
        if ($same && $current->mode === ConnectionMode::Own) {
            throw ConnectionModeTaken::by($current, $descriptor->name);
        }
        $account = $same ? $current->accountRef : null;
        if ($account === null) {
            $account = $adapter->openAccount($keys);
            $connections->write(new NewConnection($descriptor->slug, ConnectionMode::Platform, ConnectionState::Pending, null, $account));
        }

        return $adapter->onboardingLink($keys, $account, $returnUrl);
    }

    /**
     * A fresh onboarding link for the platform connection standing pending or restricted, or
     * active while its provider still lists information it needs, for the same account; null
     * where there is none to resume or this build no longer registers its provider.
     *
     * @throws PlatformUnavailable where the platform's keys for its provider are unset, the row
     *                             standing as it was and nothing asked
     */
    public function resume(PaymentConnections $connections, string $returnUrl): ?string
    {
        $current = $connections->primary();
        if ($current === null || $current->mode !== ConnectionMode::Platform || $current->accountRef === null) {
            return null;
        }
        $unfinished = in_array($current->state, [ConnectionState::Pending, ConnectionState::Restricted], true);
        if (! $unfinished && ! $current->informationDue()) {
            return null;
        }
        $adapter = $this->registry->adapter($current->provider);
        if ($adapter === null) {
            return null;
        }
        $keys = $this->platform->keys($current->provider) ?? throw PlatformUnavailable::make();

        return $adapter->onboardingLink($keys, $current->accountRef, $returnUrl);
    }
}
