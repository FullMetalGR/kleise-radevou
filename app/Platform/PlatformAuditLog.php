<?php

declare(strict_types=1);

namespace App\Platform;

use App\Infrastructure\Db\SqlText;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use stdClass;
use UnexpectedValueException;

/**
 * The platform audit log read for the Audit screen: the rows newest first, fifty a page, the
 * operator's name and the tenant's slug joined into the one statement that reads them, optionally
 * narrowed to one tenant by id. Two statements a page, the count and the rows, whatever the page
 * holds; the indexes on (created_at) and (tenant_id, created_at) serve the two orders. Rows of one
 * instant keep the order they were written in, the later first.
 *
 * Each row's action and payload are made into a PlatformAuditEntry again, so a row the writer
 * would have refused is an error here rather than a page showing it.
 */
final readonly class PlatformAuditLog
{
    public const int PAGE_SIZE = 50;

    public function __construct(private DatabaseManager $db) {}

    /**
     * The tenant a slug names, by id and its slug as the row holds it, or null where no tenant
     * holds it. The column is citext, so the case asked in does not matter.
     *
     * @return array{id: int, slug: string}|null
     */
    public function tenant(string $slug): ?array
    {
        $row = $this->db->connection()->selectOne('select id, slug from tenants where slug = ?', [$slug]);

        return $row instanceof stdClass && is_int($row->id) && is_string($row->slug) ? ['id' => $row->id, 'slug' => $row->slug] : null;
    }

    /**
     * One page of the log, counted from one, and how many rows the list holds in all.
     *
     * @return array{records: list<PlatformAuditRecord>, total: int}
     */
    public function page(?int $tenantId, int $page): array
    {
        $where = $tenantId === null ? 'true' : 'audit.tenant_id = ?';
        $bindings = $tenantId === null ? [] : [$tenantId];
        $total = $this->db->connection()->scalar('select count(*) from platform_audit_log audit where '.$where, $bindings);

        $rows = $this->db->connection()->select(sprintf(
            'select audit.id, audit.action, audit.payload::text as payload, %s as created_at, actor.name as operator_name, tenant.slug as tenant_slug
             from platform_audit_log audit
             join operators actor on actor.id = audit.operator_id
             left join tenants tenant on tenant.id = audit.tenant_id
             where %s
             order by audit.created_at desc, audit.id desc
             limit ? offset ?',
            SqlText::instantColumn('audit.created_at'),
            $where,
        ), [...$bindings, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE]);

        return [
            'records' => array_map(self::record(...), array_values($rows)),
            'total' => is_int($total) ? $total : throw new UnexpectedValueException('The platform audit log counted no integer.'),
        ];
    }

    private static function record(mixed $row): PlatformAuditRecord
    {
        if (! $row instanceof stdClass
            || ! is_int($row->id)
            || ! is_string($row->action)
            || ! is_string($row->payload)
            || ! is_string($row->created_at)
            || ! is_string($row->operator_name)
            || ! ($row->tenant_slug === null || is_string($row->tenant_slug))) {
            throw new UnexpectedValueException('A platform audit row came back in a shape this reader does not know.');
        }

        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        $createdAt = DateTimeImmutable::createFromFormat(SqlText::INSTANT, $row->created_at, new DateTimeZone('UTC'));

        if (! is_array($payload) || $createdAt === false) {
            throw new UnexpectedValueException(sprintf('The platform audit row %d holds a payload or an instant this reader cannot read.', $row->id));
        }

        return new PlatformAuditRecord(
            $row->id,
            new PlatformAuditEntry(PlatformAuditAction::from($row->action), $payload),
            $row->operator_name,
            $row->tenant_slug,
            $createdAt,
        );
    }
}
