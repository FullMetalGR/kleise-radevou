<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Notification\Messenger;
use App\Infrastructure\Db\SettingsReader;
use App\Tenancy\Tenant;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Mail\Mailer;
use Psr\Log\LoggerInterface;

/**
 * The one place the mail seam is built for a tenant, so a message and the off switches applied
 * to it can never belong to different tenants.
 *
 * The platform's own address is read here rather than inside the seam, because it is one
 * setting for the whole deployment and reading it per message would be reading it per booking.
 */
final readonly class Messengers
{
    public function __construct(
        private Mailer $mailer,
        private LoggerInterface $log,
        private SettingsReader $settings,
        #[Config('mail.from.address')] private string $platformAddress,
        #[Config('mail.from.name')] private ?string $platformName = null,
    ) {}

    public function for(Tenant $tenant): Messenger
    {
        return new MailedMessages($this->mailer, $this->log, $this->settings, $tenant, $this->platformAddress, $this->platformName);
    }
}
