<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Clock;
use Throwable;

/**
 * The one live call of the Payments section: the stored credentials lent to their own provider's
 * check, and the answer landed on the row, so every later render reads the stored result and
 * calls nobody.
 *
 * Asked after a save has committed and from Test connection, never inside a booking transaction
 * or under a staff-day lock. A check that throws, which its contract says it never does, is
 * landed as a failed check whose reason names the class thrown and nothing it said, and is
 * logged once at warning with the class and its message. A reason and that message are struck of
 * every value the connection declares before either is stored or logged, a secret field's or
 * not, whatever the adapter struck itself, since the row's reason reaches the screen and the log
 * reaches whoever reads it. A check that throws lands with THREW filled as its stored English
 * and REASON_UNEXPECTED as its key, the class its one value, named by get_debug_type so an
 * anonymous class carries no NUL byte and no path; each recorded value of an adapter's own check
 * is struck as the reason is.
 */
final readonly class TestConnection
{
    public const string THREW = 'The connection test stopped on an unexpected %s.';

    public const string REASON_UNEXPECTED = ConnectionCheck::REASONS.'unexpected';

    public const string STRUCK = '[secret]';

    public function __construct(
        private ProviderRegistry $registry,
        private Clock $clock,
        private PaymentLog $log,
    ) {}

    /**
     * Check the primary own-mode connection and land the result. Null, with nothing called and
     * nothing landed, where no own-mode connection of a registered provider holds credentials.
     */
    public function test(PaymentConnections $connections): ?ConnectionCheck
    {
        $connection = $connections->primary();
        $adapter = $connection === null ? null : $this->registry->adapter($connection->provider);

        if ($connection === null || $adapter === null || $connection->mode !== ConnectionMode::Own || ! $connection->hasCredentials) {
            return null;
        }

        $check = $connections->withCredentials($adapter, fn (Credentials $credentials): ConnectionCheck => $this->checked($connection->provider, $adapter, $credentials));

        if (! $check instanceof ConnectionCheck) {
            return null;
        }

        $connections->landCheck($check, $this->clock->now());

        return $check;
    }

    private function checked(string $provider, PaymentAdapter $adapter, Credentials $credentials): ConnectionCheck
    {
        try {
            $check = $adapter->check($credentials);
        } catch (Throwable $thrown) {
            $class = get_debug_type($thrown);
            $this->log->checkThrew($provider, $class, self::struck($thrown->getMessage(), $adapter, $credentials));

            return ConnectionCheck::failed(sprintf(self::THREW, $class), self::REASON_UNEXPECTED, ['class' => $class]);
        }

        if ($check->ok || $check->reason === null) {
            return $check;
        }

        return ConnectionCheck::failed(
            self::struck($check->reason, $adapter, $credentials),
            $check->key,
            array_map(fn (string $value): string => self::struck($value, $adapter, $credentials), $check->values),
        );
    }

    /**
     * The text with every declared field's value the credentials hold replaced by STRUCK, in one
     * pass that tries the longest value first at each position, so a value that is part of
     * another is never left half struck inside it and a value is never looked for inside a
     * STRUCK already written. A secret's value is struck wherever it occurs, since no part of it
     * may stay readable; a non-secret's where it stands as a whole word, since a short code such
     * as "1" or "eu" occurs inside times, error codes and words that are nobody's secret, and a
     * reason struck of every digit reads as nothing. A word is a word in any script, so the
     * pattern reads the text as UTF-8, which the text and every value are checked for first: a
     * text or a value that is not, which no request admits and no sealed row holds, has the
     * whole text struck, as does any other way preg_replace answers null, since nothing of a
     * text the strike could not read may stay readable.
     */
    private static function struck(string $text, PaymentAdapter $adapter, Credentials $credentials): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return self::STRUCK;
        }

        /** @var list<array{string, bool}> $values each declared value the credentials hold and whether its field is secret */
        $values = [];

        foreach ($adapter->descriptor()->credentialFields as $field) {
            $value = $credentials->field($field->name);

            if ($value !== '') {
                $values[] = [$value, $field->secret];
            }
        }

        if ($values === []) {
            return $text;
        }

        if (array_any($values, fn (array $value): bool => ! mb_check_encoding($value[0], 'UTF-8'))) {
            return self::STRUCK;
        }

        usort($values, fn (array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));
        $alternatives = array_map(
            fn (array $value): string => $value[1] ? preg_quote($value[0], '/') : '(?<!\w)'.preg_quote($value[0], '/').'(?!\w)',
            $values,
        );

        return preg_replace('/'.implode('|', $alternatives).'/u', self::STRUCK, $text) ?? self::STRUCK;
    }
}
