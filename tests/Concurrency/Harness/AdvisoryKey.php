<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

/**
 * The two-integer advisory lock keys one run uses. A nonce chosen per run keeps two runs apart,
 * the class half tells the barrier from the checkpoints and one worker from another, and the
 * name half of a checkpoint key is a hash of the name. Both halves are int4 on the server.
 */
final readonly class AdvisoryKey
{
    private const int BARRIER_CLASS = 731000;

    private const int CHECKPOINT_CLASS = 731001;

    private function __construct(
        public int $class,
        public int $key,
    ) {}

    public static function barrier(int $nonce): self
    {
        return new self(self::BARRIER_CLASS, $nonce);
    }

    public static function checkpoint(int $nonce, int $workerIndex, string $name): self
    {
        return new self(self::CHECKPOINT_CLASS + $workerIndex, self::int4(crc32($nonce.':'.$name)));
    }

    /**
     * crc32 is unsigned on 64-bit PHP and the server side of the key is a signed int4.
     */
    private static function int4(int $unsigned): int
    {
        return $unsigned > 0x7FFFFFFF ? $unsigned - 0x100000000 : $unsigned;
    }
}
