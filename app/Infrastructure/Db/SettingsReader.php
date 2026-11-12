<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Payment\ConnectionState;
use App\Application\Payment\PaymentConnection;
use App\Site\SiteLanguage;
use App\Tenancy\MapState;
use App\Tenancy\Profile;
use App\Tenancy\Settings;
use App\Tenancy\Tenant;
use App\Tenancy\Theme;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * Reads a tenant's settings row for a request that opens no transaction: the hosted page, the
 * config endpoint, the hold's planner, the mail seam and the reminder's scheduler, which read one
 * row and write nothing; and the tenant's chosen language alone, for the resolver of the four
 * languages, App\Tenancy\Languages.
 *
 * The two setup markers are selected as booleans, whether each instant is set, so the read
 * parses no instant at all: the checklist asks whether, never when, Settings.
 *
 * The profile's text columns, Profile, come back as written, each null where the owner left it
 * empty, for the hosted page, the config's Important info and the settings screen.
 *
 * The cover's path and the gallery's, in the order added, come back for the hosted page, the
 * settings screen and the image route's lookup.
 *
 * Whether the tenant takes payments is read in the same statement, from the primary payment
 * connection's state and stored check, and decided by PaymentConnection::working, the rule the
 * provider is chosen by: it is what holds the approved key on whatever emails_off says, so every
 * reader of the switch meets the lock through Settings and the read stays one statement.
 *
 * The off-switch column and the gallery come back cast to text and parsed here, because the query
 * builder hands a text[] back as the array literal Postgres prints and the grammar for reading one
 * lives in one place.
 *
 * **Nothing here caches, and the read count is the tolerance.** The seam reads this row once per
 * message it sends, MailedMessages::sends, so a business message to N recipients reads it N times;
 * a job that asks the seam whether a key is on reads it once more, the approved message and the
 * auto-approval notice about another key, and the nag about its own before it mints any link,
 * SendApprovalNag; and the reminder's scheduling reads it once per landing for the lead. Each is
 * one statement, an indexed read of the settings row with the primary connection row's two columns
 * beside it, and none is memoised because the switch is read at send time on purpose: a cached
 * answer would be a second idea of what the tenant switched off, held for as long as the cache,
 * and the seam and the sentence that points at another message must read one answer.
 *
 * The table is named by schema in the query itself, and nothing is set on the connection. That is
 * the other way to honour the pooling rule: the runner's SET LOCAL search_path is safe because it
 * is transaction-scoped, and a schema-qualified name is safe because it needs no search path at
 * all. Either way a pooled connection carries nothing from this request into the next. The runner
 * is not used here because a read that costs one statement has no transaction to run in, and the
 * runner refuses to open one inside another.
 */
final readonly class SettingsReader
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * The language the tenant chose for itself in Settings, settings.language, null where it chose
     * none and the tenant default is tenants.locale: the one column, read alone, since the
     * resolver of the four languages asks it on every tenant request and needs nothing else of the
     * row. A value outside the column's CHECK throws, as a row shaped otherwise does.
     */
    public function language(Tenant $tenant): ?SiteLanguage
    {
        $row = $this->db->connection()->table($tenant->schema_name.'.settings')->where('id', 1)->select('language')->first();

        if ($row === null) {
            throw new LogicException(sprintf('Tenant "%s" has no settings row; provisioning inserts one.', $tenant->slug));
        }

        $language = $row->language;

        if ($language === null) {
            return null;
        }

        return (is_string($language) ? SiteLanguage::tryFrom($language) : null)
            ?? throw new LogicException(sprintf('The settings row of tenant "%s" is not shaped as the schema declares.', $tenant->slug));
    }

    /**
     * The tenant's named theme, settings.theme: the one column, read alone, since the admin's root
     * view writes it on the body of every page it renders, the sign-in page among them, and needs
     * nothing else of the row. A missing or unknown word reads as neutral, Theme::read.
     */
    public function theme(Tenant $tenant): Theme
    {
        return Theme::read($this->db->connection()->table($tenant->schema_name.'.settings')->where('id', 1)->value('theme'));
    }

    public function read(Tenant $tenant): Settings
    {
        $connection = $this->db->connection();
        $primary = fn (string $column): Builder => $connection->table($tenant->schema_name.'.payment_connections')->whereRaw('is_primary')->select($column);
        $row = $connection
            ->table($tenant->schema_name.'.settings')
            ->where('id', 1)
            ->selectRaw('granularity_min, start_interval_min, checkout_ttl_min, approval_ttl_hours, payment_ttl_hours, reminder_lead_hours, emails_off::text as emails_off, start_of_week, date_format, time_format, brand_logo_path, cover_path, gallery_paths::text as gallery_paths, lat, lng, map_path, map_state, colour_by, (hours_confirmed_at is not null) as hours_confirmed, (link_copied_at is not null) as link_copied, '.implode(', ', Profile::FIELDS))
            ->selectSub($primary('state'), 'payment_state')
            ->selectSub($primary('last_check_ok'), 'payment_check_ok')
            ->first();

        if ($row === null) {
            throw new LogicException(sprintf('Tenant "%s" has no settings row; provisioning inserts one.', $tenant->slug));
        }

        $granularity = $row->granularity_min;
        $checkoutTtl = $row->checkout_ttl_min;
        $approvalTtl = $row->approval_ttl_hours;
        $reminderLead = $row->reminder_lead_hours;
        $emailsOff = $row->emails_off;
        $startOfWeek = $row->start_of_week;
        $dateFormat = $row->date_format;
        $timeFormat = $row->time_format;
        $paymentTtl = $row->payment_ttl_hours;
        $logoPath = $row->brand_logo_path;
        $startInterval = $row->start_interval_min;
        $hoursConfirmed = $row->hours_confirmed;
        $linkCopied = $row->link_copied;
        $coverPath = $row->cover_path;
        $galleryPaths = $row->gallery_paths;
        $lat = $row->lat;
        $lng = $row->lng;
        $mapPath = $row->map_path;
        $mapState = is_string($row->map_state) ? MapState::tryFrom($row->map_state) : null;
        $paymentState = $row->payment_state;
        $paymentCheckOk = $row->payment_check_ok;
        $colourBy = $row->colour_by;
        // No primary connection row reads as null; a row's state is one of the enum's, the
        // column's CHECK, and any other value throws below as map_state's does.
        $connection = is_string($paymentState) ? ConnectionState::tryFrom($paymentState) : null;
        $profile = [];

        foreach (Profile::FIELDS as $field) {
            $value = $row->{$field};

            if ($value !== null && ! is_string($value)) {
                throw new LogicException(sprintf('The settings row of tenant "%s" is not shaped as the schema declares.', $tenant->slug));
            }

            $profile[$field] = $value;
        }

        if (! is_int($granularity) || ! is_int($checkoutTtl) || ! is_int($approvalTtl) || ! is_int($paymentTtl)
            || ! is_int($reminderLead) || ! is_string($emailsOff) || ! is_int($startOfWeek) || ! is_string($dateFormat) || ! is_string($timeFormat) || ($logoPath !== null && ! is_string($logoPath))
            || ! is_int($startInterval) || ! is_bool($hoursConfirmed) || ! is_bool($linkCopied) || ($coverPath !== null && ! is_string($coverPath)) || ! is_string($galleryPaths)
            || ($lat !== null && ! is_float($lat)) || ($lng !== null && ! is_float($lng)) || ($mapPath !== null && ! is_string($mapPath)) || $mapState === null
            || ($paymentState !== null && $connection === null) || ($paymentCheckOk !== null && ! is_bool($paymentCheckOk)) || ! in_array($colourBy, Settings::COLOUR_BY, true)) {
            throw new LogicException(sprintf('The settings row of tenant "%s" is not shaped as the schema declares.', $tenant->slug));
        }

        return new Settings($granularity, $checkoutTtl, $approvalTtl, $reminderLead, TextArray::decode($emailsOff), $startOfWeek, $dateFormat, $timeFormat, $paymentTtl, $logoPath, $startInterval, $hoursConfirmed, $linkCopied, Profile::of($profile), $coverPath, TextArray::decode($galleryPaths), $lat, $lng, $mapPath, $mapState, $connection !== null && PaymentConnection::working($connection, $paymentCheckOk), $colourBy);
    }
}
