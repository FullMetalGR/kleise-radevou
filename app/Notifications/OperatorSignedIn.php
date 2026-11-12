<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use App\Platform\ConsoleTime;
use App\Platform\Operator;
use App\Site\Brand;
use DateTimeImmutable;

/**
 * The console's sign-in notice: the platform's own message, sent in its name through the
 * platform's seam to the operator's own address after every sign-in the door lets through, naming
 * the instant in the console's zone and the address the request came from, so a stolen password
 * shows itself the first time somebody uses it. It is about no tenant, so its message names none
 * and a failed send is logged against the kind alone. The console is named by the public name,
 * config('site.name').
 *
 * Sent at once and queued nowhere, after the door has regenerated the session; the seam never
 * throws, so a transport that fails leaves the sign-in standing. The door reaches the seam through
 * this class and never through the port.
 */
final readonly class OperatorSignedIn
{
    /** The subject, the public name filled in. */
    public const string SUBJECT = 'A sign-in to the %s console';

    /**
     * What to do where the reader did not sign in: change the password, through the one command
     * that changes an operator's.
     */
    public const string NOT_YOU = 'If this was not you, someone else knows your password: change it at once with the console:set-operator-password command.';

    public function __construct(
        private PlatformMessenger $platform,
        private ConsoleTime $time,
        private Brand $brand,
    ) {}

    public function send(Operator $operator, DateTimeImmutable $at, string $address): void
    {
        $this->platform->send(PlatformMessageKind::OperatorSignIn, null, fn (): PlatformMessage => new PlatformMessage(
            PlatformMessageKind::OperatorSignIn,
            null,
            $operator->email,
            $operator->name,
            sprintf(self::SUBJECT, $this->brand->name),
            implode("\n", [
                sprintf('Hello %s,', $operator->name),
                '',
                sprintf('You signed in to the %s console on %s, from the address %s.', $this->brand->name, $this->time->stamp($at), $address),
                '',
                self::NOT_YOU,
            ]),
        ));
    }
}
