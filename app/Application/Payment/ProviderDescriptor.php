<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * What one provider declares about itself, eleven things. The registry holds one per provider
 * and everything outside an adapter reads the descriptor and nothing else: no screen,
 * controller or use case names a provider.
 */
final readonly class ProviderDescriptor
{
    /**
     * @param  string  $slug  the stable identifier, used in URLs and stored on rows
     * @param  string  $name  what a tenant sees
     * @param  list<ConnectionMode>  $modes  which of platform and own it supports
     * @param  list<CredentialField>  $credentialFields  for own mode: the fields, their labels, help, and which are secret
     * @param  OnboardingEntry|null  $onboardingEntry  for platform mode: how a connection is started
     * @param  FeeMechanism  $feeMechanism  for platform mode: how the fee is attached, or none
     * @param  WebhookSignature  $webhookSignature  how an inbound notification is verified
     * @param  bool  $supportsRefundFlag  whether a note can be left on a payment
     * @param  list<string>  $currencies  which currencies it can take, as three-letter codes
     * @param  string  $rateUrl  the https address of the provider's own published processing rate, linked
     *                           beside both modes in the Payments section
     * @param  bool|null  $canExpireCheckout  whether an open checkout session can be ended before its own end; null is
     *                                        a descriptor that left the question unanswered, which the registry refuses
     */
    public function __construct(
        public string $slug,
        public string $name,
        public array $modes,
        public array $credentialFields,
        public ?OnboardingEntry $onboardingEntry,
        public FeeMechanism $feeMechanism,
        public WebhookSignature $webhookSignature,
        public bool $supportsRefundFlag,
        public array $currencies,
        public string $rateUrl,
        public ?bool $canExpireCheckout = null,
    ) {}

    public function offers(ConnectionMode $mode): bool
    {
        return in_array($mode, $this->modes, true);
    }
}
