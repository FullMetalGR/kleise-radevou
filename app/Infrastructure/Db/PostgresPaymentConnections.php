<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Payment\AccountStanding;
use App\Application\Payment\ConnectionCheck;
use App\Application\Payment\ConnectionMode;
use App\Application\Payment\ConnectionState;
use App\Application\Payment\Credentials;
use App\Application\Payment\NewConnection;
use App\Application\Payment\PaymentAdapter;
use App\Application\Payment\PaymentConnection;
use App\Application\Payment\PaymentConnections;
use App\Tenancy\Tenant;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * One tenant's payment_connections rows.
 *
 * The credentials are encrypted with the framework's encrypter before they reach the row, as the
 * JSON object of the fields the provider declared. No statement here selects that column: the read
 * asks only whether it holds anything, so a secret has no way out through this class whatever is
 * asked of it.
 *
 * v1 keeps one connection. A write clears the table and inserts the row as the primary inside one
 * transaction, so a tenant switching provider or mode never holds two rows, and never two modes of
 * one provider at once.
 *
 * The connection's central routing row, in payment_routes, joins that transaction: the tenant's
 * rows go and the new one is routed, or nothing is. Both tables are in the one database and the
 * write runs on the one connection, so a failure anywhere in it rolls both back and leaves the
 * connection and its route as they stood before.
 *
 * The one statement that selects the credentials column is withCredentials', which decrypts
 * them into a Credentials lent to one call of the adapter of the row's own provider.
 *
 * The Payments section's other writes each stand alone: a check's landing, a platform account's
 * standing and the switch are one statement each, switching a platform row back on landing it
 * where its provider last left it, pending while no standing says otherwise; the rotation and the removal touch the routing row too, so each is one
 * transaction over both rows. None of them selects or writes the credentials.
 */
final readonly class PostgresPaymentConnections implements PaymentConnections
{
    public function __construct(
        private DatabaseManager $db,
        private StringEncrypter $encrypter,
        private Tenant $tenant,
    ) {}

    public function primary(): ?PaymentConnection
    {
        $found = $this->db->connection()->selectOne(
            'select provider, mode, state, account_ref, requirements::text as requirements, '.SqlText::instantColumn('last_checked_at')
            .' as last_checked_at, last_check_ok, credentials is not null as has_credentials from '.$this->table().' where is_primary',
        );

        if (! is_object($found)) {
            return null;
        }

        $row = (array) $found;
        $provider = $row['provider'] ?? null;
        $mode = is_string($row['mode'] ?? null) ? ConnectionMode::tryFrom($row['mode']) : null;
        $state = is_string($row['state'] ?? null) ? ConnectionState::tryFrom($row['state']) : null;
        $accountRef = $row['account_ref'] ?? null;
        $requirements = $row['requirements'] ?? null;
        $checkedAt = $row['last_checked_at'] ?? null;
        $checkOk = $row['last_check_ok'] ?? null;
        $hasCredentials = $row['has_credentials'] ?? null;

        if (! is_string($provider) || $mode === null || $state === null || ($accountRef !== null && ! is_string($accountRef)) || ($requirements !== null && ! is_string($requirements))
            || ($checkedAt !== null && ! is_string($checkedAt)) || ($checkOk !== null && ! is_bool($checkOk)) || ! is_bool($hasCredentials)) {
            throw new LogicException(sprintf('The payment connection of tenant "%s" is not shaped as the schema declares.', $this->tenant->slug));
        }

        $outstanding = $requirements === null ? null : json_decode($requirements, true, 512, JSON_THROW_ON_ERROR);

        return new PaymentConnection(
            $provider,
            $mode,
            $state,
            $accountRef,
            is_array($outstanding) ? $outstanding : null,
            $checkedAt === null ? null : SqlText::readInstant($checkedAt),
            $checkOk,
            $hasCredentials,
        );
    }

    public function write(NewConnection $connection): void
    {
        $columns = [
            'provider' => $connection->provider,
            'mode' => $connection->mode->value,
            'state' => $connection->state->value,
            'account_ref' => $connection->accountRef,
            'credentials' => $connection->credentials === null
                ? null
                : $this->encrypter->encryptString(json_encode($connection->credentials, JSON_THROW_ON_ERROR)),
            'webhook_token' => $connection->webhookToken,
            'is_primary' => true,
        ];
        $database = $this->db->connection();

        $route = $connection->webhookToken === null && $connection->accountRef === null ? null : [
            'tenant_id' => $this->tenant->id,
            'provider' => $connection->provider,
            'webhook_token' => $connection->webhookToken,
            'account_ref' => $connection->webhookToken === null ? $connection->accountRef : null,
        ];

        $database->transaction(function () use ($database, $columns, $route): void {
            $database->table($this->table())->delete();
            $database->table($this->table())->insert($columns);
            $database->table('public.payment_routes')->where('tenant_id', $this->tenant->id)->delete();

            if ($route !== null) {
                $database->table('public.payment_routes')->insert($route);
            }
        });
    }

    public function withCredentials(PaymentAdapter $adapter, Closure $call): mixed
    {
        $found = $this->db->connection()->selectOne(
            'select credentials from '.$this->table().' where is_primary and provider = ? and credentials is not null',
            [$adapter->descriptor()->slug],
        );
        $sealed = is_object($found) ? ((array) $found)['credentials'] ?? null : null;

        if (! is_string($sealed)) {
            return null;
        }

        $fields = json_decode($this->encrypter->decryptString($sealed), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($fields)) {
            throw new LogicException(sprintf('The payment credentials of tenant "%s" are not an object of fields.', $this->tenant->slug));
        }

        $lent = [];

        foreach ($fields as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $lent[$name] = $value;
            }
        }

        return $call(new Credentials($lent));
    }

    public function recordCheck(bool $ok, DateTimeImmutable $at): void
    {
        $this->db->connection()->update(
            'update '.$this->table().' set last_checked_at = ?::timestamptz, last_check_ok = ?::boolean, updated_at = now() where is_primary',
            [SqlText::instant($at), $ok ? 'true' : 'false'],
        );
    }

    public function landCheck(ConnectionCheck $check, DateTimeImmutable $at): bool
    {
        $requirements = match (true) {
            $check->ok => null,
            $check->key === null => json_encode(['reason' => $check->reason ?? ''], JSON_THROW_ON_ERROR),
            default => json_encode(['reason' => $check->reason ?? '', 'key' => $check->key, 'values' => (object) $check->values], JSON_THROW_ON_ERROR),
        };

        return $this->db->connection()->update(
            'update '.$this->table().' set state = case when state in (\'disabled\', \'pending\') then state when ?::boolean then \'active\' else \'restricted\' end,'
            .' requirements = ?::jsonb, last_check_ok = ?::boolean, last_checked_at = ?::timestamptz, updated_at = now() where is_primary',
            [$check->ok ? 'true' : 'false', $requirements, $check->ok ? 'true' : 'false', SqlText::instant($at)],
        ) > 0;
    }

    public function setEnabled(bool $enabled): bool
    {
        $state = $enabled
            ? 'case when state <> \'disabled\' then state when last_check_ok is false then \'restricted\''
                .' when last_check_ok is null and mode = \'platform\' then \'pending\' else \'active\' end'
            : '\'disabled\'';

        return $this->db->connection()->update(
            'update '.$this->table().' set state = '.$state.', updated_at = now() where is_primary',
        ) > 0;
    }

    public function landStanding(string $accountRef, AccountStanding $standing, DateTimeImmutable $at): bool
    {
        $taking = match ($standing->state) {
            ConnectionState::Active => 'true',
            ConnectionState::Restricted => 'false',
            default => null,
        };

        return $this->db->connection()->update(
            'update '.$this->table().' set state = case when state = \'disabled\' then state else ? end, requirements = ?::jsonb,'
            .' last_check_ok = ?::boolean, last_checked_at = ?::timestamptz, updated_at = now()'
            .' where is_primary and mode = \'platform\' and account_ref = ?',
            [
                $standing->state->value,
                $standing->requirements === null ? null : json_encode($standing->requirements, JSON_THROW_ON_ERROR),
                $taking,
                SqlText::instant($at),
                $accountRef,
            ],
        ) > 0;
    }

    public function rotateToken(string $token): bool
    {
        $database = $this->db->connection();

        return $database->transaction(function () use ($database, $token): bool {
            $rotated = $database->update(
                'update '.$this->table().' set webhook_token = ?, updated_at = now() where is_primary and webhook_token is not null',
                [$token],
            ) > 0;

            if ($rotated) {
                $database->update(
                    'update public.payment_routes set webhook_token = ?, updated_at = now() where tenant_id = ? and webhook_token is not null',
                    [$token, $this->tenant->id],
                );
            }

            return $rotated;
        });
    }

    public function remove(): bool
    {
        $database = $this->db->connection();

        return $database->transaction(function () use ($database): bool {
            $removed = $database->delete('delete from '.$this->table().' where is_primary') > 0;
            $database->delete('delete from public.payment_routes where tenant_id = ?', [$this->tenant->id]);

            return $removed;
        });
    }

    public function webhookToken(): ?string
    {
        $token = $this->db->connection()->scalar('select webhook_token from '.$this->table().' where is_primary');

        return is_string($token) ? $token : null;
    }

    private function table(): string
    {
        return $this->tenant->schema_name.'.payment_connections';
    }
}
