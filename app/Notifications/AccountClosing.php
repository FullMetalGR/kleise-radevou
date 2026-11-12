<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Billing\TrialAccount;
use App\Platform\AccountOwners;
use App\Platform\ClosingWindow;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Attributes\Config;
use LogicException;

/**
 * The closing email: the platform's own message, sent in its name through the platform's seam to
 * every owner user of a closing tenant, the kind closing whichever opening it carries. It names
 * the business, the retention window and the day it ends in the tenant's clock with the zone
 * named once, what keeps working until then, and the way back. It is sent at once, after the
 * write that closed the account has committed, and queued nowhere. Each owner's message is written
 * by a closure the seam runs inside its never-throw promise, so an owner whose message could not
 * be rendered, or whom the transport failed for, is a logged error and the others are still sent
 * theirs.
 *
 * Four openings. The platform's close thirty days after a trial ended unpaid names when the trial
 * ended, and its way back is a plan chosen on the Billing screen inside the window. The owner's own
 * close from the Settings Danger zone names when the close was made and that nothing more is
 * charged, the provider subscription cancelled with it; its way back is the same plan on the
 * Billing screen, or, for a free tenant whose Billing screen offers no plan, a line to support at
 * the platform's address. The console's close names the platform as the closer, when, and that
 * nothing more is charged, then gives the operator's reason as it was written, a paragraph of its
 * own after the line that introduces it, as the suspension's notice gives its own; its way back is
 * the owner's close's. The retention sweep's close of a lapsed subscriber names the day the
 * subscription's last billing period ended, or, where no period was ever recorded, that it ended
 * with none on record, or, where its first payment never went through, that it ended for that,
 * naming no period since none was paid for; and that no plan has been chosen since. Its way back
 * is the owner's close's, the Billing screen, since a canceled row is never the free shape.
 *
 * It is a branded mail in its recipient's language: the words are mail.php's in the owner's
 * users.locale, else the tenant default; the instants are spelled by the locale-aware formatter
 * with the weekday and the month in that language, and the same words go out as HTML in the one
 * layout beside the text. What every owner reads alike, the opening, the way back, the subject, the
 * window's end and the reason, is composed once per language among them. The window's sentence and
 * what stays are one paragraph, as the text has always read them, on adjacent lines.
 */
final readonly class AccountClosing
{
    private const string UNCLOSED = 'A closing account carries the instant it was closed.';

    public function __construct(
        private PlatformMessenger $platform,
        private TenantUrls $urls,
        private BrandedMails $mails,
        #[Config('site.contact_address')] private string $support,
    ) {}

    /**
     * Hands the platform's closing email to the seam once for each owner of a tenant the hourly
     * lapse closed thirty days after its trial ended unpaid.
     */
    public function send(TrialAccount $account): void
    {
        $closedAt = $account->closingAt ?? throw new LogicException(self::UNCLOSED);

        $this->deliver($account->tenant, $account->name, $account->zone, $account->owners, $closedAt, fn (BrandedMail $mail): array => [
            [$mail->line('closing.ended', ['business' => $account->name, 'end' => $mail->instant($account->trialEndsAt, $account->zone)])],
            [$mail->line('closing.billing'), new MailLink($this->urls->billing($account->slug))],
        ]);
    }

    /**
     * Hands the own-close variant to the seam once for each owner of a tenant closed from its
     * Settings Danger zone at $closedAt; $free is the free shape, whose way back is support.
     */
    public function ownClose(AccountOwners $account, DateTimeImmutable $closedAt, bool $free): void
    {
        $this->deliver($account->tenant, $account->name, $account->zone, $account->owners, $closedAt, fn (BrandedMail $mail): array => [
            [$mail->line('closing.set', ['business' => $account->name, 'at' => $mail->instant($closedAt, $account->zone)])],
            $this->wayBack($mail, $account, $free),
        ]);
    }

    /**
     * Hands the console's variant to the seam once for each owner of a tenant the operator closed
     * at $closedAt with the reason given, as it was written; $free is the free shape, whose way
     * back is support.
     */
    public function consoleClose(AccountOwners $account, DateTimeImmutable $closedAt, bool $free, string $reason): void
    {
        $this->deliver($account->tenant, $account->name, $account->zone, $account->owners, $closedAt, fn (BrandedMail $mail): array => [
            [
                $mail->line('closing.console', ['business' => $account->name, 'at' => $mail->instant($closedAt, $account->zone)]),
                $mail->line('closing.because'),
                $reason,
            ],
            $this->wayBack($mail, $account, $free),
        ]);
    }

    /**
     * Hands the lapsed subscriber's variant to the seam once for each owner of a tenant the
     * retention sweep closed at $closedAt, thirty days after its subscription's last period ended
     * at $periodEnd, or after its last update where no period was recorded and $periodEnd is
     * null; $free is the free shape, which a canceled row never is. $neverPaid is a subscription
     * whose first payment never went through, which names no period, since none was paid for.
     */
    public function lapsedClose(AccountOwners $account, DateTimeImmutable $closedAt, bool $free, ?DateTimeImmutable $periodEnd, bool $neverPaid = false): void
    {
        $this->deliver($account->tenant, $account->name, $account->zone, $account->owners, $closedAt, fn (BrandedMail $mail): array => [
            [match (true) {
                $neverPaid => $mail->line('closing.lapsed_unpaid', ['business' => $account->name]),
                $periodEnd === null => $mail->line('closing.lapsed_unrecorded', ['business' => $account->name]),
                default => $mail->line('closing.lapsed', ['business' => $account->name, 'end' => $mail->instant($periodEnd, $account->zone)]),
            }],
            $this->wayBack($mail, $account, $free),
        ]);
    }

    /**
     * The way back of a close the owner, the console or the sweep made: a plan on the Billing
     * screen with its address, or, for a free tenant, the line to support.
     *
     * @return list<string|MailLink>
     */
    private function wayBack(BrandedMail $mail, AccountOwners $account, bool $free): array
    {
        return $free
            ? [$mail->line('closing.support', ['address' => $this->support])]
            : [$mail->line('closing.billing'), new MailLink($this->urls->billing($account->slug))];
    }

    /**
     * One message per owner, in the owner's language: the greeting, the opening's paragraphs, the
     * window with what stays, the way back and the zone, the window's end thirty days after the
     * closing in the tenant's clock. $variant words the opening and the way back in one language,
     * asked once for each language among the owners.
     *
     * @param  list<array{address: string, name: string, locale: SiteLanguage|null}>  $owners
     * @param  Closure(BrandedMail): array{list<string>, list<string|MailLink>}  $variant
     */
    private function deliver(Tenant $tenant, string $business, DateTimeZone $zone, array $owners, DateTimeImmutable $closedAt, Closure $variant): void
    {
        $composed = [];

        foreach ($owners as $owner) {
            $this->platform->send(PlatformMessageKind::Closing, $tenant->id, function () use ($tenant, $business, $zone, $owner, $closedAt, $variant, &$composed): PlatformMessage {
                $mail = $this->mails->to($tenant, $owner['locale']);
                [$opening, $wayBack, $subject, $window, $reason] = $composed[$mail->language->value] ??= [
                    ...$variant($mail),
                    $mail->choice('closing.subject', ClosingWindow::days()),
                    $mail->instant(ClosingWindow::endOf($closedAt), $zone),
                    $mail->line('closing.reason', ['business' => $business]),
                ];
                $paragraphs = [
                    $mail->line('greeting', ['owner' => $owner['name']]),
                    ...$opening,
                    $mail->choice('closing.kept', ClosingWindow::days(), ['until' => $window])."\n".$mail->line('closing.what_stays'),
                    ...$wayBack,
                    $mail->line('closing.zone', ['zone' => $zone->getName()]),
                ];

                return new PlatformMessage(
                    PlatformMessageKind::Closing,
                    $tenant->id,
                    $owner['address'],
                    $owner['name'],
                    $subject,
                    BrandedMail::text($paragraphs),
                    html: $mail->html($subject, $paragraphs, $reason),
                );
            });
        }
    }
}
