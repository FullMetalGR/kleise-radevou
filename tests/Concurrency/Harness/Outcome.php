<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

use LogicException;
use PDOException;
use Throwable;

/**
 * What one worker's closure did: returned a value, or threw. An exception is carried as its
 * class, its message and the first SQLSTATE in its chain, because the worker's exception object
 * cannot cross a process boundary and those three are what a concurrency test asserts on.
 */
final readonly class Outcome
{
    private function __construct(
        public string $worker,
        private mixed $value,
        public ?string $exceptionClass,
        public ?string $message,
        public ?string $sqlstate,
    ) {}

    public static function ofReturn(string $worker, mixed $value): self
    {
        return new self($worker, $value, null, null, null);
    }

    public static function ofThrowable(string $worker, Throwable $exception): self
    {
        return new self($worker, null, $exception::class, $exception->getMessage(), self::sqlstateOf($exception));
    }

    public function threw(): bool
    {
        return $this->exceptionClass !== null;
    }

    /**
     * The returned value. Asking a worker that threw for its value fails with the exception it
     * threw, so a test reads the real failure rather than a mismatch against null.
     */
    public function returned(): mixed
    {
        if ($this->exceptionClass !== null) {
            throw new LogicException(sprintf('Worker "%s" %s.', $this->worker, $this->describe()));
        }

        return $this->value;
    }

    public function describe(): string
    {
        if ($this->exceptionClass === null) {
            return 'returned '.var_export($this->value, true);
        }

        return sprintf(
            'threw %s%s: %s',
            $this->exceptionClass,
            $this->sqlstate === null ? '' : ' [SQLSTATE '.$this->sqlstate.']',
            (string) $this->message,
        );
    }

    public function toWire(): string
    {
        return base64_encode(serialize([
            'value' => $this->value,
            'exceptionClass' => $this->exceptionClass,
            'message' => $this->message,
            'sqlstate' => $this->sqlstate,
        ]));
    }

    public static function fromWire(string $worker, string $wire): self
    {
        $data = unserialize(base64_decode($wire, true) ?: '');

        if (! is_array($data) || ! array_key_exists('value', $data)) {
            throw new LogicException(sprintf('Worker "%s" reported an outcome the harness cannot read.', $worker));
        }

        return new self(
            $worker,
            $data['value'],
            self::stringOrNull($data['exceptionClass'] ?? null),
            self::stringOrNull($data['message'] ?? null),
            self::stringOrNull($data['sqlstate'] ?? null),
        );
    }

    /**
     * The first SQLSTATE in the chain, outermost first. PDO carries it in errorInfo and the
     * framework's QueryException copies it into the code; a domain exception wrapping either is
     * reached through getPrevious().
     */
    private static function sqlstateOf(Throwable $exception): ?string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && is_string($current->errorInfo[0] ?? null) && $current->errorInfo[0] !== '') {
                return $current->errorInfo[0];
            }

            $code = (string) $current->getCode();

            if (preg_match('/^[0-9A-Z]{5}$/', $code) === 1) {
                return $code;
            }
        }

        return null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
