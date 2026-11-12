<?php

declare(strict_types=1);

namespace App\Platform;

use App\Infrastructure\Db\SqlText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A person who signs in to the platform's console: a name, an email unique as citext and a bcrypt
 * password, and nothing tying them to a tenant. Never a row in users, which belongs to tenants; the
 * console's guard reads this model alone and the admin's reads User alone. One role, every power:
 * the table allows a second operator and roles wait for a second person.
 *
 * Added by console:add-operator and by nothing else; nobody registers. The password changes through
 * console:set-operator-password alone.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password'])]
final class Operator extends Authenticatable
{
    /**
     * The format every date this model writes crosses in, the house's instant with the offset
     * written out, as the tenant's row writes its own.
     */
    public function getDateFormat(): string
    {
        return SqlText::INSTANT;
    }

    /**
     * No remember token: the table has no column for one and the console's sign-in remembers
     * nobody, so the guard neither reads one nor writes one.
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
