<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessageKind;
use App\Application\Notification\PlatformMessenger;
use Closure;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * The platform's seam as a test meets it where the moment of the handover is under test: every
 * message it is handed, in order, sent nowhere, each beside the transaction level the default
 * connection stood at as it was handed over. A message sent after its write has committed, in a
 * file that wraps no test in a transaction, is handed over at nought.
 */
final class LevelledPlatformMessages implements PlatformMessenger
{
    /** @var list<array{message: PlatformMessage, level: int}> */
    public array $sent = [];

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @param  Closure(): PlatformMessage  $render
     */
    public function send(PlatformMessageKind $kind, ?int $tenantId, Closure $render): void
    {
        $message = $render();

        if ($message->kind !== $kind || $message->tenantId !== $tenantId) {
            throw new LogicException('A platform message was written under another kind or about another tenant than it was handed under.');
        }

        $this->sent[] = ['message' => $message, 'level' => $this->db->connection()->transactionLevel()];
    }

    /**
     * Each message as its address, its kind and the transaction level it was handed over at.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(fn (array $sent): string => sprintf('%s %s %d', $sent['message']->toAddress, $sent['message']->kind->value, $sent['level']), $this->sent);
    }

    /**
     * The message handed over at the position given, counted from nought.
     */
    public function message(int $index): PlatformMessage
    {
        return ($this->sent[$index] ?? null)['message'] ?? throw new LogicException(sprintf('No message was handed over at %d.', $index));
    }

    /**
     * The body of the first message to the address given, the empty string where none went there.
     */
    public function bodyTo(string $address): string
    {
        foreach ($this->sent as $sent) {
            if ($sent['message']->toAddress === $address) {
                return $sent['message']->body;
            }
        }

        return '';
    }
}
