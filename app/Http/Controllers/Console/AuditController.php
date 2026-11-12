<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Requests\ConsoleAuditRequest;
use App\Platform\ConsoleTime;
use App\Platform\PlatformAuditKey;
use App\Platform\PlatformAuditLog;
use App\Platform\PlatformAuditRecord;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The Audit screen, GET /audit under operator: the platform audit log newest first, fifty a page,
 * inside the console's shell. Each row carries the instant spelled on the console's clock, the
 * top bar naming the zone once for the screen, the operator's name, the action as its vocabulary
 * value, which the page words, the tenant's slug or null, and the payload's facts in the key
 * list's order.
 *
 * ?tenant={slug} narrows the list to the tenant that slug names, and the filter hands back the
 * slug as the row holds it; a malformed slug, or one no tenant holds, reads as unset, the whole
 * list. ?page= picks the page, anything malformed reading as the first. The filter is the
 * request's own, read eager because it costs nothing, and the page a closure, so paging is a
 * partial reload of it alone.
 */
final readonly class AuditController
{
    public function __construct(private PlatformAuditLog $log, private ConsoleTime $time) {}

    public function __invoke(ConsoleAuditRequest $request): InertiaResponse
    {
        $slug = $request->tenant();
        $tenant = $slug === null ? null : $this->log->tenant($slug);
        $page = $request->page();

        return Inertia::render('console/Audit', [
            'filters' => ['tenant' => $tenant['slug'] ?? null],
            'entries' => function () use ($tenant, $page): array {
                $read = $this->log->page($tenant['id'] ?? null, $page);

                return [
                    'rows' => array_map($this->row(...), $read['records']),
                    'total' => $read['total'],
                    'page' => $page,
                    'page_size' => PlatformAuditLog::PAGE_SIZE,
                ];
            },
        ]);
    }

    /**
     * @return array{id: int, at: string, operator: string, action: string, tenant: string|null, facts: list<array{key: string, value: scalar|list<scalar>}>}
     */
    private function row(PlatformAuditRecord $record): array
    {
        $facts = [];

        foreach (PlatformAuditKey::cases() as $key) {
            if (array_key_exists($key->value, $record->entry->payload)) {
                $facts[] = ['key' => $key->value, 'value' => $record->entry->payload[$key->value]];
            }
        }

        return [
            'id' => $record->id,
            'at' => $this->time->onScreen($record->createdAt),
            'operator' => $record->operatorName,
            'action' => $record->entry->action->value,
            'tenant' => $record->tenantSlug,
            'facts' => $facts,
        ];
    }
}
