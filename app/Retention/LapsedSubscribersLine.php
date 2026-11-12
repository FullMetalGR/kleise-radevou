<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use App\Notifications\AccountClosing;
use App\Platform\AccountClose;
use App\Platform\CentralRow;
use App\Platform\CloseRefused;
use App\Platform\LapsedClose;
use App\Tenancy\Tenant;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\DatabaseManager;
use stdClass;

/**
 * The lapsed subscribers: an active row left canceled with no trial end, a former subscriber the
 * hourly lapse never reaches, closed by the retention sweep thirty days after its last period's
 * end, or its last update where no period was recorded, as LapsedClose spells the rule. The drop
 * ends it thirty days after that. It runs after the tenant lines and before the drop.
 *
 * It is no line of the table's one-statement shape but a close per row through the one writer,
 * AccountClose, as the drop is a transaction per tenant: the due rows are read by id in id order,
 * the demo tenant never, as the drop reads its own; each is closed in the writer's transaction of
 * its own, under the row's lock with the rule asked again of the row as it stands, closing_at the
 * clock's now and the status closing; and once that has committed the owners are mailed the
 * closing email's lapsed subscriber's opening, the canceled row cancelling nothing at the provider.
 * A row the lock finds no longer due, a subscription landed in between, is passed over, nothing
 * written and nothing sent. A close that throws stops the line there, the rows closed before it
 * standing, and the sweep logs it as it logs any line. A second run finds every row it closed
 * closing, so nothing is due twice.
 *
 * What it answers is how many rows it closed, which the sweep logs by its table, tenants, and
 * nothing of a row.
 */
final readonly class LapsedSubscribersLine implements RetentionLine
{
    /** How the line names itself when a row it reads cannot be read. */
    private const string READER = 'The lapsed subscribers\' close';

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private AccountClose $close,
        private AccountClosing $closing,
        #[Config('site.demo_slug')]
        private string $demoSlug,
    ) {}

    public function table(): string
    {
        return 'tenants';
    }

    public function apply(): int
    {
        $closed = 0;

        foreach ($this->due() as $tenant) {
            try {
                $this->close->close($tenant, new LapsedClose($this->closing, $this->clock));
            } catch (CloseRefused) {
                continue;
            }

            $closed++;
        }

        return $closed;
    }

    /**
     * The tenants due, in id order.
     *
     * @return list<Tenant>
     */
    private function due(): array
    {
        $rows = $this->db->connection()->select(
            'select t.id from tenants t left join subscriptions s on s.tenant_id = t.id
             where '.LapsedClose::DUE.' and t.slug <> ? order by t.id',
            [...LapsedClose::dueBindings($this->clock), $this->demoSlug],
        );

        $ids = array_map(
            fn (mixed $row): int => $row instanceof stdClass && is_int($row->id ?? null) ? $row->id : throw CentralRow::misshapen('id', self::READER),
            array_values($rows),
        );

        return $ids === [] ? [] : array_values(Tenant::query()->whereKey($ids)->orderBy('id')->get()->all());
    }
}
