<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Models\User;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use DateTimeZone;
use Illuminate\Container\Attributes\Config;
use LogicException;

/**
 * The welcome email: the platform's own message, sent in its name through the platform's seam to
 * the owner once, by the verification click that built the trial and no other, after the build
 * has committed, at once and never queued. It names the business, its sign-in address and its
 * booking page's address, the trial's end in the tenant's clock with the zone named once, three
 * first steps, and the set-up offer: a reply to the contact address, site.contact_address, and
 * the set-up is done with the owner (the benchmark's decision 19). A click that builds nothing
 * sends nothing, so no second click sends a second welcome; a free tenant the console creates
 * goes through the provisioner's other path and gets none. The seam never throws: a transport
 * that fails is a logged error, the build standing.
 *
 * It is a branded mail in the owner's language: the words are mail.php's in their users.locale,
 * else the tenant default, an owner the build has just made having chosen none; the end spelled
 * by the locale-aware formatter with the weekday and the month in that language, and the same
 * words go out as HTML in the one layout beside the text, the two addresses as links.
 */
final readonly class Welcome
{
    private const string UNBUILT = 'A built trial carries the instant it ends.';

    public function __construct(
        private PlatformMessenger $platform,
        private TenantUrls $urls,
        private BrandedMails $mails,
        #[Config('site.contact_address')] private string $contactAddress,
    ) {}

    /**
     * Hands the welcome to the seam for the owner, from the row and the owner as the build left
     * them.
     */
    public function send(Tenant $tenant, User $owner): void
    {
        $slug = Slug::of($tenant->slug);
        $end = $tenant->trial_ends_at ?? throw new LogicException(self::UNBUILT);

        $this->platform->send(PlatformMessageKind::Welcome, $tenant->id, function () use ($tenant, $owner, $slug, $end): PlatformMessage {
            $zone = new DateTimeZone($tenant->timezone);
            $mail = $this->mails->to($tenant, $owner->locale);
            $subject = $mail->line('welcome.subject', ['business' => $tenant->name]);
            $paragraphs = [
                $mail->line('greeting', ['owner' => $owner->name]),
                $mail->line('welcome.ready', ['business' => $tenant->name, 'end' => $mail->instant($end, $zone)]),
                $mail->line('welcome.sign_in'),
                new MailLink($this->urls->signIn($slug)),
                $mail->line('welcome.page'),
                new MailLink($this->urls->origin($slug)),
                $mail->line('welcome.steps'),
                $mail->line('welcome.step_services'),
                $mail->line('welcome.step_hours'),
                $mail->line('welcome.step_share'),
                $mail->line('welcome.offer', ['contact' => $this->contactAddress]),
                $mail->line('welcome.zone', ['zone' => $zone->getName()]),
            ];

            return new PlatformMessage(
                PlatformMessageKind::Welcome,
                $tenant->id,
                $owner->email,
                $owner->name,
                $subject,
                BrandedMail::text($paragraphs),
                html: $mail->html($subject, $paragraphs, $mail->line('welcome.reason', ['business' => $tenant->name])),
            );
        });
    }
}
