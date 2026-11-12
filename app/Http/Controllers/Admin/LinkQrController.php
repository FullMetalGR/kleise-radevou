<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Enum\BookingChannel;
use App\Http\LinkKind;
use App\Infrastructure\Db\LinkTargets;
use App\Infrastructure\Qr\QrSvg;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The QR codes of the Links card: GET /admin/settings/links/page.svg for the booking page and
 * GET /admin/settings/links/{kind}/{id}.svg for a service's or a member's link, each an SVG the
 * server draws, QrSvg. Under manageSettings, declared on the routes, and never the subscription
 * gate: drawing a code is a read and configures nothing.
 *
 * A code encodes the row's link tagged ch=qr, TenantUrls::bookingLink, so a booking made from a
 * printed code is recorded under the qr channel, the one value of the fixed list nothing else
 * hands out. A kind with no link, an id no row of that kind has and the implicit member, who
 * stands for the business and is never a choice, are the framework's 404. A row not offered
 * online is still drawn: its link is its link, and the flow's judge-once check decides what a
 * service it cannot find means.
 *
 * Cached a day in the browser alone: the answer is the admin's, behind a session, and a link
 * changes only with the slug, which never changes.
 */
final readonly class LinkQrController
{
    public const string CACHE = 'private, max-age=86400';

    public function __construct(
        private CurrentTenant $current,
        private LinkTargets $targets,
        private TenantUrls $urls,
    ) {}

    public function page(): Response
    {
        return $this->drawn([]);
    }

    public function show(string $kind, int $id): Response
    {
        $known = LinkKind::tryFrom($kind);

        if ($known === null || ! $this->targets->exists($this->current->get(), $known, $id)) {
            throw new NotFoundHttpException;
        }

        return $this->drawn([$known->value => $id]);
    }

    /**
     * @param  array<string, int>  $preselected
     */
    private function drawn(array $preselected): Response
    {
        $link = $this->urls->bookingLink(Slug::of($this->current->get()->slug), [...$preselected, 'ch' => BookingChannel::Qr->value]);

        return new Response(QrSvg::of($link), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => self::CACHE,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
