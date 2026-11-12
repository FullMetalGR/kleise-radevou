<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Site\Demo;
use App\Tenancy\Tenant;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The demo's drop in front of the platform seam's adapter: the port's binding, which hands
 * every message but one about the demo on to MailedPlatformMessages as it is, and sends
 * nothing about the demo. The kinds that can name the demo are the console's, a set-password
 * press, a suspension and a resumption, since the demo signs up for nothing and is billed for
 * nothing.
 *
 * The question is asked of the kind and the tenant's id the caller hands over beside the closure
 * that writes the message, before anything is written, so a message about the demo is never
 * rendered. The label is found by reading the row the id names and asking the guard, which reads
 * the configured label and nothing else; a message about no tenant names nobody and is sent. The
 * drop sits here rather than in the adapter because the adapter is built by hand with no database
 * in its own unit test, and the id's question cannot be answered without the row.
 *
 * It never throws, as the adapter never does: a row that cannot be read is one logged error
 * naming the tenant's id and the kind, and the message is not sent, since whether it is about
 * the demo is unknown and the promise is that nothing leaves for it. The drop is one line
 * naming the label and the kind, and never an address, as every line the two seams write. The
 * closure is handed on unrun, so its render stays inside the adapter's promise.
 */
final readonly class DemoDroppedPlatformMessages implements PlatformMessenger
{
    public function __construct(
        private MailedPlatformMessages $mailed,
        private Demo $demo,
        private LoggerInterface $log,
    ) {}

    /**
     * @param  Closure(): PlatformMessage  $render
     */
    public function send(PlatformMessageKind $kind, ?int $tenantId, Closure $render): void
    {
        try {
            $tenant = $tenantId === null ? null : Tenant::query()->find($tenantId);
        } catch (Throwable $failure) {
            $this->log->error('A platform message could not be told from the demo\'s', [
                'tenant' => $tenantId,
                'kind' => $kind->value,
                'exception' => $failure::class,
            ]);

            return;
        }

        if ($tenant instanceof Tenant && $this->demo->is($tenant)) {
            $this->log->info('A platform message about the demo was not sent', ['tenant' => $tenant->slug, 'kind' => $kind->value]);

            return;
        }

        $this->mailed->send($kind, $tenantId, $render);
    }
}
