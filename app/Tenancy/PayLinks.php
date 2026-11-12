<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Http\ManageTokenGuard;
use App\Site\SiteLanguage;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Routing\UrlGenerator;

/**
 * Where the approved message's payment link is minted: a signed URL on the tenant's own
 * subdomain naming one booking, the credential an approved customer holds in place of the
 * manage token the approval never saw.
 *
 * **The signature freezes no instant of the hold.** Whether the link may still be paid is the
 * hold's own question, asked of the row as it stands every time the page renders or the act
 * posts: the hop may move the hold's end to the provider session's, and a link frozen at the
 * approval would then answer for a hold that is over or refuse one that is live.
 *
 * **It has an outer life all the same**, the manage link's: thirty days after the booking's
 * last segment ends. A signature with no expiry would answer forever, and would be revoked only
 * by rotating the application's key; the page it opens names the tenant and a status and
 * nothing about the customer, but a link living in a mailbox for years should still stop. The
 * days are the token guard's own constant, so the two links a customer holds die together, and
 * are added in UTC for the reason that guard gives.
 *
 * One URI for the page and the act, as the decision links have: the GET renders and decides
 * nothing, so a mail scanner that fetches every link decides nothing either, and the POST its
 * button makes is the hop. The provider brings the customer back to the same GET.
 */
final readonly class PayLinks
{
    public const string ROUTE = 'pay.link';

    public const string UUID = 'uuid';

    public const string EXPIRES = 'expires';

    public function __construct(private UrlGenerator $urls) {}

    /**
     * @param  DateTimeImmutable  $lastEnd  when the booking's last segment ends, which its snapshot answers
     * @param  SiteLanguage|null  $prefix  the language prefix the link's page is under, none for the bare path; the signature covers the path, so the page and its act re-mint under the prefix they were opened at
     */
    public function link(Tenant $tenant, string $uuid, DateTimeImmutable $lastEnd, ?SiteLanguage $prefix = null): string
    {
        return $this->urls->signedRoute(
            TenantPages::routeName(self::ROUTE, $prefix),
            ['tenant' => $tenant->slug, self::UUID => $uuid],
            self::outerLife($lastEnd),
        );
    }

    /**
     * The instant the link stops working whatever the hold says, its derived lifetime.
     */
    public static function outerLife(DateTimeImmutable $lastEnd): DateTimeImmutable
    {
        return $lastEnd->setTimezone(new DateTimeZone('UTC'))->add(new DateInterval(sprintf('P%dD', ManageTokenGuard::LIFETIME_DAYS)));
    }
}
