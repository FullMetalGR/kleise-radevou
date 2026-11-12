<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use Closure;
use LogicException;

/**
 * The platform's seam as a test meets it: every message it is handed, in order, written by its
 * closure on the spot and sent nowhere. A message written under another kind or about another
 * tenant than it was handed under is a failed test, as the adapter refuses it.
 */
final class RecordingPlatformMessages implements PlatformMessenger
{
    /** @var list<PlatformMessage> */
    public array $sent = [];

    /**
     * @param  Closure(): PlatformMessage  $render
     */
    public function send(PlatformMessageKind $kind, ?int $tenantId, Closure $render): void
    {
        $message = $render();

        if ($message->kind !== $kind || $message->tenantId !== $tenantId) {
            throw new LogicException('A platform message was written under another kind or about another tenant than it was handed under.');
        }

        $this->sent[] = $message;
    }

    /**
     * The verification link the last message carried, the empty string when it carried none.
     */
    public function lastLink(): string
    {
        $last = $this->sent[array_key_last($this->sent) ?? 0] ?? null;

        return preg_match('~https?://\S+/signup/verify/[0-9a-f]{64}~', $last->body ?? '', $match) === 1 ? $match[0] : '';
    }
}
