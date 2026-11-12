<?php

declare(strict_types=1);

namespace App\Application\Payment;

use Closure;
use DateTimeImmutable;

/**
 * One tenant's payment connections. The table holds many rows and v1 uses one: a write
 * replaces what was there and the row it leaves is the primary.
 *
 * No operation answers a secret. Credentials go in and stay in, and come out once, through
 * withCredentials, into the adapter of the connection's own provider for the one call it is
 * lent for.
 */
interface PaymentConnections
{
    /**
     * The tenant's primary connection, or null where nothing is connected.
     */
    public function primary(): ?PaymentConnection;

    /**
     * Store this connection as the tenant's one connection and mark it primary. A connection
     * written anew has no stored check. Its central routing row, in payment_routes, is written
     * in the same transaction and the one it replaces removed with the row it replaces.
     */
    public function write(NewConnection $connection): void;

    /**
     * Store the result of a check of the primary connection and when it was made.
     */
    public function recordCheck(bool $ok, DateTimeImmutable $at): void;

    /**
     * Land a check of the primary connection: the result and when it was made, what the provider
     * said in requirements, and the state the result decides. A pass lands active and clears
     * requirements; a fail lands restricted with the reason stored verbatim as {"reason":
     * "..."}, and where the check names its reason's key, the key and the values that fill it
     * beside it, {"reason", "key", "values"}. A disabled row is the tenant's switch and keeps
     * its state, as a pending one is platform onboarding's and keeps its own; both still record
     * the result and the reason, so a re-enable reads them. One statement. False where nothing
     * is connected.
     */
    public function landCheck(ConnectionCheck $check, DateTimeImmutable $at): bool;

    /**
     * Switch the primary connection off, or back on. Off lands disabled whatever the state. On
     * moves a disabled row alone, to active where its stored check passed or none has run and
     * to restricted where it failed, and leaves any other state as it stands. One statement.
     * False where nothing is connected.
     */
    public function setEnabled(bool $enabled): bool;

    /**
     * Give the primary own-mode connection a new webhook token: the row's and its central
     * routing row's together, in one transaction, the credentials, the state and the stored
     * check untouched. False, with nothing written, where no connection holds a token.
     */
    public function rotateToken(string $token): bool;

    /**
     * Remove the primary connection and its central routing row, in one transaction. False
     * where nothing was connected.
     */
    public function remove(): bool;

    /**
     * The primary connection's webhook token, null where nothing is connected or the
     * connection names itself to its provider otherwise. A routing name and not a secret, read
     * apart from PaymentConnection so the token rides no value that is logged.
     */
    public function webhookToken(): ?string;

    /**
     * Lend the primary connection's credentials, decrypted, to one call of the adapter of that
     * connection's own provider, and answer what the call answered. Null, with nothing
     * decrypted, where the primary row is not that adapter's provider or holds no credentials.
     *
     * The credentials live in memory for the call alone, on no value that is serialized, logged,
     * queued or returned. Never asked inside a booking transaction or under a staff-day lock.
     *
     * @template T
     *
     * @param  Closure(Credentials): T  $call
     * @return T|null
     */
    public function withCredentials(PaymentAdapter $adapter, Closure $call): mixed;

    /**
     * Lands a platform account's standing, read from its provider's callback, on the connection
     * holding that account: the state the provider gave, kept disabled where the tenant switched
     * it off, what the provider said outstanding verbatim, and the time. last_check_ok records
     * whether the account takes payments, null while its onboarding is unfinished, so switching
     * back on lands where the provider last left it. False where no connection holds the account.
     */
    public function landStanding(string $accountRef, AccountStanding $standing, DateTimeImmutable $at): bool;
}
