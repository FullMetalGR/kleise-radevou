<?php

declare(strict_types=1);

namespace App\Http;

use App\Infrastructure\Db\LinkTargets;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;

/**
 * The booking links the admin hands out, as the pages' props carry them: every link spelled by
 * TenantUrls::bookingLink on the server and never built on the client, so a copy button, the Links
 * card and a QR code can only ever give out the link the hosted page reads.
 *
 * The service and staff detail pages carry the link of every row a link may name, for their Copy
 * booking link button; the Links card, carries the page's link and one row per service and member
 * the public catalogue offers, each with its link and the path of its QR code, GET
 * /admin/settings/links/... .
 */
final readonly class BookingLinks
{
    public const string QR = AdminSession::PREFIX.'/settings/links';

    public function __construct(
        private LinkTargets $targets,
        private TenantUrls $urls,
    ) {}

    /**
     * Every row of a kind a link may name, with its link.
     *
     * @return list<array{id: int, link: string}>
     */
    public function of(Tenant $tenant, LinkKind $kind): array
    {
        $slug = Slug::of($tenant->slug);

        return array_map(
            fn (array $row): array => ['id' => $row['id'], 'link' => $this->urls->bookingLink($slug, [$kind->value => $row['id']])],
            $this->targets->all($tenant, $kind),
        );
    }

    /**
     * The Links card: the page's link and the offered rows of each kind, every one with its QR
     * code's path.
     *
     * @return array{page: array{link: string, qr: string}, services: list<array{id: int, name: string, link: string, qr: string}>, staff: list<array{id: int, name: string, link: string, qr: string}>}
     */
    public function card(Tenant $tenant): array
    {
        return [
            'page' => ['link' => $this->urls->bookingLink(Slug::of($tenant->slug)), 'qr' => self::QR.'/page.svg'],
            'services' => $this->offered($tenant, LinkKind::Service),
            'staff' => $this->offered($tenant, LinkKind::Staff),
        ];
    }

    /**
     * @return list<array{id: int, name: string, link: string, qr: string}>
     */
    private function offered(Tenant $tenant, LinkKind $kind): array
    {
        $slug = Slug::of($tenant->slug);

        return array_map(fn (array $row): array => [
            ...$row,
            'link' => $this->urls->bookingLink($slug, [$kind->value => $row['id']]),
            'qr' => self::QR.'/'.$kind->value.'/'.$row['id'].'.svg',
        ], $this->targets->offered($tenant, $kind));
    }
}
