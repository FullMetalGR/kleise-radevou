<?php

namespace App\Models;

use App\Site\SiteLanguage;
use App\Tenancy\Role;
use App\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person who signs in. Belongs to exactly one tenant and carries a role. Email is unique per
 * tenant, not globally, so the same person may hold a login at two tenants.
 *
 * The locale is the language the user chose for the admin and their mails, null where they chose
 * none and read as the tenant default. It is not fillable: nothing writes it at a user's creation,
 * and the top bar's language control writes it for the signed-in user alone.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property Role $role
 * @property string|null $remember_token
 * @property CarbonImmutable|null $email_verified_at
 * @property SiteLanguage|null $locale
 */
#[Fillable(['tenant_id', 'name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token', 'password_link_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'locale' => SiteLanguage::class,
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
