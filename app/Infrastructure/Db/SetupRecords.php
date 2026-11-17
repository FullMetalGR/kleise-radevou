<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Payment\ConnectionState;
use App\Application\Payment\PaymentConnection;
use App\Setup\SetupProgress;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * Setup's facts, read and written in the business's settings row. The read is one statement: the
 * row's stamps beside a probe for a service, for a staff member beyond the business's own
 * built-in one, the count of active members, and the primary payment connection's two columns,
 * the same pair the settings read asks whether the business takes payments.
 *
 * Each write stamps one column with the database's own now() where it is still unset, so a
 * second press, a second owner or a retried post changes nothing, and nothing here ever clears
 * a stamp.
 */
final readonly class SetupRecords
{
    /** The profile's own columns, any of which set makes the step done: About, the address but its country, which every row holds by default, and the phone. */
    private const array PROFILE = ['about', 'address_line1', 'address_line2', 'city', 'postal_code', 'phone'];

    /** Each skippable step's stamp. */
    private const array SKIPS = ['team' => 'setup_team_skipped_at', 'payments' => 'setup_payments_skipped_at'];

    public function __construct(private DatabaseManager $db) {}

    public function progress(Tenant $tenant, bool $staff): SetupProgress
    {
        $row = $this->settings($tenant)
            ->selectRaw('setup_opened_at is not null as opened, setup_finished_at is not null as finished, (setup_profile_saved_at is not null or coalesce('.implode(', ', self::PROFILE).') is not null) as profile, hours_confirmed_at is not null as hours, (link_copied_at is not null or setup_link_finished_at is not null) as link, setup_team_skipped_at is not null as team_skipped, setup_payments_skipped_at is not null as payments_skipped')
            ->selectSub($this->table($tenant, 'services')->selectRaw('true')->limit(1), 'services')
            ->selectSub($this->table($tenant, 'staff')->whereRaw('not is_implicit')->selectRaw('true')->limit(1), 'team')
            ->selectSub($this->table($tenant, 'staff')->where('status', 'active')->selectRaw('count(*)'), 'active_members')
            ->selectSub($this->primary($tenant, 'state'), 'payment_state')
            ->selectSub($this->primary($tenant, 'last_check_ok'), 'payment_check_ok')
            ->first() ?? throw new LogicException(sprintf('Tenant "%s" has no settings row; provisioning inserts one.', $tenant->slug));

        $state = is_string($row->payment_state) ? ConnectionState::tryFrom($row->payment_state) : null;
        $checkOk = is_bool($row->payment_check_ok) ? $row->payment_check_ok : null;

        return SetupProgress::of(
            [
                'profile' => (bool) $row->profile,
                'services' => $row->services === true,
                'team' => $row->team === true,
                // Without the staff feature and with more than one active member, Settings shows the
                // members' weeks read-only and there is nothing for this step to edit.
                'hours' => (bool) $row->hours || (! $staff && is_numeric($row->active_members) && (int) $row->active_members > 1),
                'payments' => $state !== null && PaymentConnection::working($state, $checkOk),
                'link' => (bool) $row->link,
            ],
            ['team' => (bool) $row->team_skipped, 'payments' => (bool) $row->payments_skipped],
            $staff,
            (bool) $row->opened,
            (bool) $row->finished,
        );
    }

    /** Records that the wizard came up by itself; the opening is then spent for the business. */
    public function markOpened(Tenant $tenant): void
    {
        $this->stamp($tenant, 'setup_opened_at');
    }

    /** Records that every step that applies was done or skipped; setup is then over for good. */
    public function markFinished(Tenant $tenant): void
    {
        $this->stamp($tenant, 'setup_finished_at');
    }

    /** Records a skip of one of the skippable steps. */
    public function markSkipped(Tenant $tenant, string $step): void
    {
        $this->stamp($tenant, self::SKIPS[$step] ?? throw new LogicException(sprintf('The setup step "%s" cannot be skipped.', $step)));
    }

    /** Records that the business profile was saved from the wizard, which makes its step done even when left empty. */
    public function markProfileSaved(Tenant $tenant): void
    {
        $this->stamp($tenant, 'setup_profile_saved_at');
    }

    /** Records Finish on the Booking link step, which makes it done whether or not the link was copied. */
    public function markLinkFinished(Tenant $tenant): void
    {
        $this->stamp($tenant, 'setup_link_finished_at');
    }

    private function stamp(Tenant $tenant, string $column): void
    {
        $this->settings($tenant)->whereNull($column)->update([$column => $this->db->connection()->raw('now()')]);
    }

    private function settings(Tenant $tenant): Builder
    {
        return $this->table($tenant, 'settings')->where('id', 1);
    }

    private function primary(Tenant $tenant, string $column): Builder
    {
        return $this->table($tenant, 'payment_connections')->whereRaw('is_primary')->select($column);
    }

    private function table(Tenant $tenant, string $table): Builder
    {
        return $this->db->connection()->table($tenant->schema_name.'.'.$table);
    }
}
