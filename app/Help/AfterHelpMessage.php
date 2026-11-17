<?php

declare(strict_types=1);

namespace App\Help;

use App\Notifications\HelpMessageReceived;
use App\Notifications\HelpReplied;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Container\Container;

/**
 * What happens after a help message is stored, by either side: called once per message, after the
 * write's transaction has committed, never inside it. A business's message sends the mail to the
 * support mailbox; an operator's reply sends the mail to each of the business's users who wrote in
 * the thread. The business is read here, at send time, by its key.
 *
 * A failure here never fails the write: the caller catches whatever this throws and logs it by the
 * ticket and the message, so the write stands and its answer is the one it would have been.
 *
 * It holds the container alone and builds the two mails when a message comes, so building it opens
 * no connection and reads no configuration, wherever it is built.
 *
 * Not final, so a test can stand in for it and see what it is handed and when.
 */
readonly class AfterHelpMessage
{
    public function __construct(private Container $container) {}

    public function handle(HelpMessageStored $stored): void
    {
        $tenant = Tenant::query()->find($stored->tenantId);

        if (! $tenant instanceof Tenant) {
            return;
        }

        match ($stored->side) {
            HelpSide::Business => $this->container->make(HelpMessageReceived::class)->send($stored, $tenant->name),
            HelpSide::Platform => $this->container->make(HelpReplied::class)->send($stored, $tenant),
        };
    }
}
