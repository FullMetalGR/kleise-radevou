<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Application\TenantContext;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Db\TextArray;
use App\Models\User;
use App\Site\SiteLanguage;
use Carbon\CarbonImmutable;
use Database\Factories\Tenancy\TenantFactory;
use DateTimeZone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * The row every request resolves before it touches anything else.
 *
 * The slug is the only identity the model takes as input. It is validated and lowercased on the way
 * in, and the schema name is derived from it in the same step: nothing sets the schema name
 * directly. The slug never changes once the row exists, because it is baked into every embed
 * already pasted onto a tenant's live site. The one exception is the drop, the retention sweep's
 * last line (App\Platform\TenantDrop), which nulls it in its own statement on the row it writes
 * closed, releasing the address; reading the slug of a closed row raises, since no reader of a
 * tenant by this model has a use for a released address, and every reader of a closed row reads the
 * central row's columns (the console's list and detail).
 *
 * The billing columns are not fillable. The subscription sync writes them and nothing else does.
 * Nor is the locale, the language the signup was made in: the provisioner writes it from the
 * signup, English by default, and nothing changes it afterwards. Nor are the terms' two columns:
 * the reservation records the owner's acceptance, the instant and the version as Y-m-d text, and
 * only the demo's rebuild writes them afterwards, null on its own row.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $schema_name
 * @property string $timezone
 * @property string $currency
 * @property TenantStatus $status
 * @property list<string> $entitlements
 * @property list<string> $narrowed
 * @property string|null $plan_ref
 * @property string $subscription_state
 * @property CarbonImmutable|null $grace_ends_at
 * @property CarbonImmutable|null $closing_at
 * @property CarbonImmutable|null $closed_at
 * @property string|null $category
 * @property CarbonImmutable|null $trial_ends_at
 * @property string|null $trial_three_days_sent_at
 * @property string|null $trial_ends_today_sent_at
 * @property string|null $suspended_at
 * @property string|null $suspension_reason
 * @property SiteLanguage $locale
 * @property CarbonImmutable|null $terms_accepted_at
 * @property string|null $terms_version
 */
#[Fillable(['slug', 'name', 'timezone', 'currency'])]
class Tenant extends Model implements TenantContext
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /**
     * Mirrors the table's defaults, so a model just created reads the same as its row.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'entitlements' => '{}',
        'narrowed' => '{}',
        'subscription_state' => 'active',
        'locale' => 'en',
    ];

    /**
     * The format every date this model writes crosses in: the house's own instant,
     * SqlText::INSTANT, microseconds and the offset written out, so a timestamptz column holds the
     * true instant whatever zone the connection's session keeps, and to the microsecond. The
     * model's own timestamps are the case: created_at is when a signup reserved its slug, and the
     * sweep measures its day from it against the application's clock.
     */
    public function getDateFormat(): string
    {
        return SqlText::INSTANT;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'entitlements' => TextArray::class,
            'narrowed' => TextArray::class,
            'grace_ends_at' => 'immutable_datetime',
            'closing_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'trial_ends_at' => 'immutable_datetime',
            'terms_accepted_at' => 'immutable_datetime',
            'locale' => SiteLanguage::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Tenant $tenant): void {
            if ($tenant->isDirty('slug')) {
                throw new LogicException('A tenant slug is immutable.');
            }
        });
    }

    /**
     * Every tenant whose schema stands built, in id order: no row provisioning, whose build has
     * not committed, and no row closed, whose schema the drop removed (TenantStatus::schemaless()).
     * The one set every loop over the tenants that opens a schema, or queues a job that does,
     * reads: tenant:migrate, holds:sweep, map:backfill and retention:sweep's tenant lines.
     *
     * @return Builder<self>
     */
    public static function built(): Builder
    {
        return self::query()
            ->whereNotIn('status', array_map(fn (TenantStatus $status): string => $status->value, TenantStatus::schemaless()))
            ->orderBy('id');
    }

    /**
     * @return Attribute<string, Slug|string>
     */
    protected function slug(): Attribute
    {
        // Without object caching: a Slug handed to the setter is no answer of the getter's, which
        // reads the column's text.
        return Attribute::make(
            get: fn (mixed $value): string => is_string($value) ? $value : throw new LogicException(sprintf('Tenant %d is closed, and the drop released its slug.', $this->id)),
            set: function (Slug|string $value): array {
                $slug = $value instanceof Slug ? $value : Slug::of($value);

                return ['slug' => $slug->value, 'schema_name' => $slug->schemaName()];
            },
        )->withoutObjectCaching();
    }

    /**
     * @return Attribute<string, never>
     */
    protected function schemaName(): Attribute
    {
        return Attribute::make(
            set: function (): never {
                throw new LogicException('A tenant schema name is derived from the slug and cannot be set.');
            },
        );
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The zone the tenant's clock runs in, the one every staff-day is counted in.
     */
    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }

    public function currencyCode(): string
    {
        return $this->currency;
    }
}
