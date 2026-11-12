<?php

declare(strict_types=1);

namespace App\Application\Payment;

use SensitiveParameter;

/**
 * A connection on its way into the store. The one moment a tenant's credentials are held in
 * the clear: the store encrypts them before they reach the row and nothing reads them back.
 */
final readonly class NewConnection
{
    /**
     * @param  string  $provider  the registry slug
     * @param  array<string, string>|null  $credentials  own mode: the fields the provider declared, by name; null in platform mode
     * @param  string|null  $accountRef  platform mode: the provider's connected-account identifier
     * @param  string|null  $webhookToken  own mode: the opaque token of the webhook URL
     */
    public function __construct(
        public string $provider,
        public ConnectionMode $mode,
        public ConnectionState $state,
        #[SensitiveParameter]
        public ?array $credentials = null,
        public ?string $accountRef = null,
        public ?string $webhookToken = null,
    ) {}
}
