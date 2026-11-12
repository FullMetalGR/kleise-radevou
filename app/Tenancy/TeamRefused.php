<?php

declare(strict_types=1);

namespace App\Tenancy;

use RuntimeException;

/**
 * A Team write the guards refuse: the last owner demoted or removed, a user removing themself,
 * or an address a user of the tenant took between the form's check and the insert. Each carries
 * the code the wire answers and the key of admin/team.php the controller words in the request's
 * language; nothing was written.
 */
final class TeamRefused extends RuntimeException
{
    public const string LAST_OWNER = 'last_owner';

    public const string SELF = 'self';

    public const string EMAIL_TAKEN = 'email_taken';

    private const array KEYS = [
        self::LAST_OWNER => 'admin/team.refusals.last_owner',
        self::SELF => 'admin/team.refusals.self',
        self::EMAIL_TAKEN => 'admin/team.validation.email_taken',
    ];

    private function __construct(public readonly string $refusal)
    {
        parent::__construct(sprintf('A Team write was refused: %s.', $refusal));
    }

    public static function lastOwner(): self
    {
        return new self(self::LAST_OWNER);
    }

    public static function removingSelf(): self
    {
        return new self(self::SELF);
    }

    public static function emailTaken(): self
    {
        return new self(self::EMAIL_TAKEN);
    }

    /** The key of admin/team.php the refusal is worded by. */
    public function key(): string
    {
        return self::KEYS[$this->refusal];
    }
}
