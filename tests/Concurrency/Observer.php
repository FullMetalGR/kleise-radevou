<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every statement the connection under test is asked to run, and every log record written,
 * from the moment it attaches. The statements come from the connection's before-executing hook
 * rather than the query log, because the query log records a statement only after it succeeds
 * and the statement a test cares about is the one that failed, or the one that must not follow
 * it. Autoloaded, so a harness worker can attach one and return what it saw.
 */
final class Observer
{
    /** @var list<string> */
    public array $statements = [];

    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $logs = [];

    public static function attach(): self
    {
        $observer = new self;

        DB::beforeExecuting(function (string $query) use ($observer): void {
            $observer->statements[] = $query;
        });

        Log::listen(function (MessageLogged $event) use ($observer): void {
            $observer->logs[] = [
                'level' => $event->level,
                'message' => $event->message,
                'context' => $event->context,
            ];
        });

        return $observer;
    }

    public function lastStatement(): ?string
    {
        $key = array_key_last($this->statements);

        return $key === null ? null : $this->statements[$key];
    }

    /**
     * How many statements so far begin with the given text.
     */
    public function count(string $prefix): int
    {
        return count(array_filter($this->statements, fn (string $statement): bool => str_starts_with($statement, $prefix)));
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    public function atLevel(string $level): array
    {
        return array_values(array_filter($this->logs, fn (array $log): bool => $log['level'] === $level));
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    public function logged(string $message): array
    {
        return array_values(array_filter($this->logs, fn (array $log): bool => $log['message'] === $message));
    }
}
