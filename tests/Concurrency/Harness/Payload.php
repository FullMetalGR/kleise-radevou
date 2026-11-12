<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

use Closure;
use Laravel\SerializableClosure\UnsignedSerializableClosure;
use LogicException;

/**
 * Everything a worker process needs, handed to it on stdin: which worker it is, the closure to
 * run, the checkpoint names the test holds, the nonce the run's lock keys derive from, and the
 * connection the test is using so the worker opens its own connection to the same database.
 */
final readonly class Payload
{
    /**
     * @param  list<string>  $held
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $worker,
        public int $index,
        public int $nonce,
        public array $held,
        public UnsignedSerializableClosure $closure,
        public string $connection,
        public array $config,
        public string $database,
    ) {}

    public function toWire(): string
    {
        return serialize($this);
    }

    public static function fromWire(string $wire): self
    {
        $payload = unserialize($wire);

        if (! $payload instanceof self) {
            throw new LogicException('The worker was started without a payload it can read.');
        }

        return $payload;
    }

    public function closure(): Closure
    {
        return $this->closure->getClosure();
    }
}
