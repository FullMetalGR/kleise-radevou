<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Payment\ConnectionCheck;
use App\Application\Payment\ConnectionMode;
use App\Application\Payment\CredentialField;
use App\Application\Payment\PaymentConnection;
use App\Application\Payment\PlatformMode;
use App\Application\Payment\ProviderDescriptor;
use App\Application\Payment\ProviderRegistry;
use App\Http\Requests\PaymentCredentialsRequest;
use App\Infrastructure\PaymentFactory;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;

/**
 * The Settings page's payments prop: data and no copy, built from the provider registry and the
 * tenant's stored row alone, so the screen is generated from declarations and opening it calls no
 * provider.
 *
 * Each provider carries its slug, its name, the address of its own published processing rate (the
 * descriptor's rate_url, linked beside both modes), the modes it supports with whether each is
 * offered and whether it takes the platform's fee, and its credential fields as the descriptor
 * declares them, the label and the help our words for them read in the request's language from
 * the keys the descriptor names. Own mode takes nothing and is offered wherever it is declared,
 * its fee null. Platform mode takes a percentage of each transaction, a fixed part, or both, and
 * is offered only while configuration holds both that fee, under payments.platform_fee
 * (PlatformFee), and the platform's own keys for the provider, under payments.platform
 * (PlatformMode). An offered platform mode carries the fee as configured, so the tenant reads it
 * before choosing: its percentage as a decimal string, and its fixed part in the tenant's own
 * currency's minor units with that currency, null where it names none for it; a mode not offered
 * carries null.
 *
 * The connection, or null, carries what the row stores: provider, mode, state, the last check's
 * instant in UTC with its offset and its result, what the provider last said (a reason of ours
 * worded by requirements(), anything else as stored), whether a connection made through us
 * takes payments while its provider still lists information it needs
 * (PaymentConnection::informationDue), the names of the secret fields holding a value, never a
 * value, and the webhook URL with its token where the connection has one. The credentials are
 * stored as one sealed object written whole, so every secret field of a connection holding
 * credentials holds a value.
 *
 * A connection made through us also carries the fee in force, in the offered mode's shape and
 * read from configuration on the same render, so a fee raised after connecting is seen there
 * rather than discovered after the fact: a fee configured nowhere comes to nothing, since
 * payments then open with no fee. An own-mode connection takes no fee and carries null.
 */
final readonly class PaymentsSection
{
    public function __construct(
        private ProviderRegistry $registry,
        private PaymentFactory $payments,
        private TenantUrls $urls,
        private PlatformMode $platform,
    ) {}

    /**
     * @return array{
     *     providers: list<array{
     *         slug: string,
     *         name: string,
     *         rate_url: string,
     *         modes: list<array{
     *             mode: string,
     *             offered: bool,
     *             takes_fee: bool,
     *             fee: array{percent: string|null, fixed_minor: int|null, currency: string}|null,
     *         }>,
     *         fields: list<array{name: string, label: string, help: string, secret: bool}>,
     *     }>,
     *     connection: array{
     *         provider: string,
     *         name: string,
     *         mode: string,
     *         state: string,
     *         last_checked_at: string|null,
     *         last_check_ok: bool|null,
     *         requirements: array<array-key, mixed>|null,
     *         information_due: bool,
     *         fee: array{percent: string|null, fixed_minor: int|null, currency: string}|null,
     *         stored_secrets: list<string>,
     *         webhook_url: string|null,
     *     }|null,
     * }
     */
    public function prop(Tenant $tenant): array
    {
        $connections = $this->payments->connections($tenant);
        $connection = $connections->primary();

        return [
            'providers' => array_map(fn (ProviderDescriptor $descriptor): array => $this->provider($descriptor, $tenant->currency), $this->registry->descriptors()),
            'connection' => $connection === null ? null : $this->connection($connection, $connections->webhookToken(), $tenant->currency),
        ];
    }

    /**
     * @return array{
     *     slug: string,
     *     name: string,
     *     rate_url: string,
     *     modes: list<array{
     *         mode: string,
     *         offered: bool,
     *         takes_fee: bool,
     *         fee: array{percent: string|null, fixed_minor: int|null, currency: string}|null,
     *     }>,
     *     fields: list<array{name: string, label: string, help: string, secret: bool}>,
     * }
     */
    private function provider(ProviderDescriptor $descriptor, string $currency): array
    {
        $fee = $this->platform->offered($descriptor->slug) ? $this->platform->fee() : null;

        return [
            'slug' => $descriptor->slug,
            'name' => $descriptor->name,
            'rate_url' => $descriptor->rateUrl,
            'modes' => array_map(fn (ConnectionMode $mode): array => [
                'mode' => $mode->value,
                'offered' => $mode === ConnectionMode::Own || $this->platform->offered($descriptor->slug),
                'takes_fee' => $mode === ConnectionMode::Platform,
                'fee' => $mode === ConnectionMode::Platform && $fee !== null
                    ? ['percent' => $fee->percent(), 'fixed_minor' => $fee->fixedIn($currency), 'currency' => $currency]
                    : null,
            ], $descriptor->modes),
            'fields' => array_map(fn (CredentialField $field): array => [
                'name' => $field->name,
                'label' => PaymentCredentialsRequest::line($field->label),
                'help' => PaymentCredentialsRequest::line($field->help),
                'secret' => $field->secret,
            ], $descriptor->credentialFields),
        ];
    }

    /**
     * @return array{
     *     provider: string,
     *     name: string,
     *     mode: string,
     *     state: string,
     *     last_checked_at: string|null,
     *     last_check_ok: bool|null,
     *     requirements: array<array-key, mixed>|null,
     *     information_due: bool,
     *     fee: array{percent: string|null, fixed_minor: int|null, currency: string}|null,
     *     stored_secrets: list<string>,
     *     webhook_url: string|null,
     * }
     */
    private function connection(PaymentConnection $connection, ?string $token, string $currency): array
    {
        $fee = $this->platform->fee();
        $descriptor = $this->registry->descriptor($connection->provider);
        $secrets = array_values(array_filter($descriptor === null ? [] : $descriptor->credentialFields, fn (CredentialField $field): bool => $field->secret));

        return [
            'provider' => $connection->provider,
            'name' => $descriptor === null ? $connection->provider : $descriptor->name,
            'mode' => $connection->mode->value,
            'state' => $connection->state->value,
            'last_checked_at' => $connection->lastCheckedAt === null ? null : BookingPayload::instant($connection->lastCheckedAt),
            'last_check_ok' => $connection->lastCheckOk,
            'requirements' => self::requirements($connection->requirements),
            'information_due' => $connection->informationDue(),
            'fee' => $connection->mode === ConnectionMode::Platform
                ? ['percent' => $fee?->percent(), 'fixed_minor' => $fee?->fixedIn($currency), 'currency' => $currency]
                : null,
            'stored_secrets' => $connection->hasCredentials ? array_map(fn (CredentialField $field): string => $field->name, $secrets) : [],
            'webhook_url' => $token === null ? null : $this->urls->central('/webhooks/'.$connection->provider.'/'.$token),
        ];
    }

    /**
     * The row's requirements as the screen quotes them. A failed check that named a key of
     * payments.php's reasons group carries its line in the request's language, filled with the
     * values the row holds, which the translator places without reading, so a provider's words
     * inside it stay as sent; a key outside that group, one the group no longer has, or values
     * not shaped as the writer stores them, strings named exactly by the line's placeholders,
     * leave the stored English reason. A row with no key and no values, written before the key
     * was recorded or by another adapter, and any other shape, the platform's requirements,
     * pass as stored; a row holding a key or values and no string reason passes without them.
     * The key and the values never leave the server.
     *
     * @param  array<array-key, mixed>|null  $requirements
     * @return array<array-key, mixed>|null
     */
    private static function requirements(?array $requirements): ?array
    {
        if ($requirements === null || (! array_key_exists('key', $requirements) && ! array_key_exists('values', $requirements))) {
            return $requirements;
        }

        $reason = $requirements['reason'] ?? null;

        if (! is_string($reason)) {
            return array_diff_key($requirements, ['key' => true, 'values' => true]);
        }

        $key = $requirements['key'] ?? null;
        $values = $requirements['values'] ?? null;

        if (! is_string($key) || ! self::reasonKey($key) || ! is_array($values)) {
            return ['reason' => $reason];
        }

        $line = PaymentCredentialsRequest::line($key);
        $filled = self::filling($values, $line);

        return ['reason' => $line === $key || $filled === null ? $reason : PaymentCredentialsRequest::line($key, $filled)];
    }

    /**
     * The values as the line's filling where they are strings named by strings, the names
     * exactly the line's placeholders, no more and no fewer; null for any other shape.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, string>|null
     */
    private static function filling(array $values, string $line): ?array
    {
        $filled = [];

        foreach ($values as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                return null;
            }

            $filled[$name] = $value;
        }

        $names = array_keys($filled);
        sort($names);

        return $names === self::placeholders($line) ? $filled : null;
    }

    /**
     * Whether the key may name a line of payments.php's reasons group, the only lines a key read
     * from a row may reach: it starts with the group and nothing after it holds a dot.
     */
    private static function reasonKey(string $key): bool
    {
        return str_starts_with($key, ConnectionCheck::REASONS) && ! str_contains(substr($key, strlen(ConnectionCheck::REASONS)), '.');
    }

    /**
     * The names of the line's placeholders, sorted and each once: every colon followed by a
     * name, as the translator reads them.
     *
     * @return list<string>
     */
    private static function placeholders(string $line): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $line, $found);
        $names = array_values(array_unique($found[1]));
        sort($names);

        return $names;
    }
}
