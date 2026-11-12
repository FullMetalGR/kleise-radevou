<?php

declare(strict_types=1);

namespace App\Application\Dto;

/**
 * What one sweep of a tenant did: whether it was ever the sweep holding the singleton lock,
 * how many holds it let go, how many requests the clock granted, and how many batches it took.
 *
 * A sweep that found another already running answers nothing(): it is not a failure and not an
 * error, it is the second runner standing down. The job logs it and the minute after tries
 * again.
 *
 * The batch count counts the transactions that found rows to settle, and not the one that finds
 * none and ends the sweep. Twelve hundred lapsed holds are three batches of five hundred, five
 * hundred and two hundred, and a fourth transaction that selects nothing and returns.
 */
final readonly class Swept
{
    private function __construct(
        public bool $ran,
        public int $expired,
        public int $confirmed,
        public int $batches,
    ) {}

    /**
     * A sweep that held the lock nowhere and wrote nothing: the value a sweep starts from, and
     * the answer of a batch whose claim on the singleton lock was refused.
     */
    public static function nothing(): self
    {
        return new self(false, 0, 0, 0);
    }

    /**
     * One batch that held the lock, settling the holds it names. A batch that held the lock and
     * found nothing counts as no batch, because there was no batch of rows in it.
     */
    public static function batch(int $expired, int $confirmed): self
    {
        return new self(true, $expired, $confirmed, $expired + $confirmed > 0 ? 1 : 0);
    }

    public function plus(self $batch): self
    {
        return new self(
            $this->ran || $batch->ran,
            $this->expired + $batch->expired,
            $this->confirmed + $batch->confirmed,
            $this->batches + $batch->batches,
        );
    }

    /**
     * How many bookings this touched, either way. Zero from a batch that held the lock is what
     * ends the sweep.
     */
    public function touched(): int
    {
        return $this->expired + $this->confirmed;
    }
}
