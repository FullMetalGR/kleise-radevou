<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Retention\TenantExport;
use App\Retention\Zip;
use App\Tenancy\CurrentTenant;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The tenant export from the Settings Danger zone: GET /admin/settings/export under
 * manageSettings and never the subscription gate, since a business taking its data out
 * configures nothing and a lapsed or closing owner must still reach it.
 *
 * TenantExport reads the whole archive, the images with it, before the response begins, so a
 * failure is an error answer and never a broken download; the response then writes the zip as it
 * goes, Zip, through no file on the server. It is an attachment named {slug}-{date}.zip with the
 * tenant's today, never cached and never sniffed, as the customer's export is.
 */
final readonly class TenantExportController
{
    public function __construct(
        private CurrentTenant $current,
        private TenantExport $export,
    ) {}

    public function __invoke(): StreamedResponse
    {
        $archive = $this->export->archive($this->current->get());

        return response()->streamDownload(function () use ($archive): void {
            Zip::stream($archive['entries'], $archive['at'], function (string $bytes): void {
                echo $bytes;
            });
        }, $archive['file'], [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
