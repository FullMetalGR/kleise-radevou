<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * A logger that keeps what it was told, so a test can read the one line a failed send leaves.
 *
 * It is the notification suites' one idea of what the seam wrote: every file that counts a
 * failure reads it from here, and a second logger would be a second idea of what a logged
 * error looks like.
 */
final class SeamLog extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * A line this logger refuses to write, so that a test can make the one thing the seam does
     * with a failure fail too. It is how a throw gets out of a seam that catches everything.
     */
    public ?string $refuses = null;

    /**
     * @param  array<mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => is_string($level) ? $level : 'unknown', 'message' => (string) $message, 'context' => $context];

        if ($this->refuses !== null && (string) $message === $this->refuses) {
            throw new RuntimeException('the log is not there either');
        }
    }

    /**
     * Everything this logger was handed, as one string, for asking whether a secret is anywhere
     * in it.
     */
    public function text(): string
    {
        return json_encode($this->records, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->records, fn (array $record): bool => $record['level'] === 'error'));
    }
}
