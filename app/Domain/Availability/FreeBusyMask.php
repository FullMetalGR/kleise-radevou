<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use InvalidArgumentException;

/**
 * A fixed-width bitset over a staff-day's slots: one bit per slot, a set bit meaning free.
 * Every operation returns a new mask and leaves its receiver as it was.
 *
 * The bits sit in native integers, sixty-four to a word, the lowest index in the lowest bit
 * of the first word, so a mask a day wide is five words and a sixty-day window at five
 * minutes is 270. Nothing outside PHP's core is needed, which is what keeps the domain
 * runnable wherever PHP is. Two facts about PHP's integers shape the arithmetic: the right
 * shift is arithmetic, so a word with its top bit set must be masked after shifting, and a
 * mask of sixty-three or sixty-four low bits cannot be built by shifting one up and
 * subtracting, so the low-bit masks come from shifting the largest int down.
 *
 * The one invariant every operation keeps is that the bits past the width are clear, so a
 * not, a shift toward lower indices or a concatenation can never let a phantom slot in.
 */
final readonly class FreeBusyMask
{
    private const int WORD = 64;

    /**
     * @param  list<int>  $words
     */
    private function __construct(
        public int $width,
        private array $words,
    ) {}

    public static function allClear(int $width): self
    {
        return new self($width, array_fill(0, self::wordsFor($width), 0));
    }

    public static function allSet(int $width): self
    {
        return self::allClear($width)->set(0, $width);
    }

    /**
     * The mask with the half-open slot range set, an empty range leaving it as it was.
     */
    public function set(int $from, int $to): self
    {
        return $this->paint($from, $to, true);
    }

    /**
     * The mask with the half-open slot range cleared, an empty range leaving it as it was.
     */
    public function clear(int $from, int $to): self
    {
        return $this->paint($from, $to, false);
    }

    public function and(self $other): self
    {
        $this->assertSameWidth($other);

        $words = [];

        foreach ($this->words as $index => $word) {
            $words[] = $word & $other->words[$index];
        }

        return new self($this->width, $words);
    }

    public function or(self $other): self
    {
        $this->assertSameWidth($other);

        $words = [];

        foreach ($this->words as $index => $word) {
            $words[] = $word | $other->words[$index];
        }

        return new self($this->width, $words);
    }

    public function not(): self
    {
        $words = [];
        $last = count($this->words) - 1;

        foreach ($this->words as $index => $word) {
            $words[] = $index === $last ? ~$word & $this->topWordMask() : ~$word;
        }

        return new self($this->width, $words);
    }

    /**
     * Bit i moved to i + n, the top n bits falling off and the bottom n filled with zeros. A
     * shift of the width or more clears everything.
     */
    public function shiftToHigher(int $slots): self
    {
        $this->assertShift($slots);

        if ($slots >= $this->width) {
            return self::allClear($this->width);
        }

        $wordShift = intdiv($slots, self::WORD);
        $bitShift = $slots % self::WORD;
        $count = count($this->words);
        $words = [];

        for ($position = 0; $position < $count; $position++) {
            $source = $position - $wordShift;
            $word = 0;

            if ($source >= 0) {
                $word = $bitShift === 0 ? $this->words[$source] : $this->words[$source] << $bitShift;

                if ($bitShift !== 0 && $source > 0) {
                    $word |= self::logicalRight($this->words[$source - 1], self::WORD - $bitShift);
                }
            }

            $words[] = $position === $count - 1 ? $word & $this->topWordMask() : $word;
        }

        return new self($this->width, $words);
    }

    /**
     * Bit i moved to i - n, the bottom n bits falling off and the top n filled with zeros.
     * A shift of the width or more clears everything.
     */
    public function shiftToLower(int $slots): self
    {
        $this->assertShift($slots);

        if ($slots >= $this->width) {
            return self::allClear($this->width);
        }

        $wordShift = intdiv($slots, self::WORD);
        $bitShift = $slots % self::WORD;
        $count = count($this->words);
        $words = [];

        for ($position = 0; $position < $count; $position++) {
            $source = $position + $wordShift;
            $word = 0;

            if ($source < $count) {
                $word = $bitShift === 0 ? $this->words[$source] : self::logicalRight($this->words[$source], $bitShift);

                if ($bitShift !== 0 && $source + 1 < $count) {
                    $word |= $this->words[$source + 1] << (self::WORD - $bitShift);
                }
            }

            $words[] = $word;
        }

        return new self($this->width, $words);
    }

    /**
     * This mask followed by another: slot k of the other sits at this width plus k, so a run
     * of days becomes one window whose shifts cross the midnights.
     */
    public function concat(self $next): self
    {
        $width = $this->width + $next->width;
        $count = self::wordsFor($width);
        $wordOffset = intdiv($this->width, self::WORD);
        $bitOffset = $this->width % self::WORD;
        $words = [];

        for ($position = 0; $position < $count; $position++) {
            $word = $this->words[$position] ?? 0;
            $source = $position - $wordOffset;

            if (isset($next->words[$source])) {
                $word |= $bitOffset === 0 ? $next->words[$source] : $next->words[$source] << $bitOffset;
            }

            if ($bitOffset !== 0 && isset($next->words[$source - 1])) {
                $word |= self::logicalRight($next->words[$source - 1], self::WORD - $bitOffset);
            }

            $words[] = $word;
        }

        return new self($width, $words);
    }

    /**
     * The mask whose bit i is set when a run of at least n free slots begins at i: shift-and
     * doubling, so a run of n is found in a logarithmic number of passes and never by
     * walking the slots. A run longer than the mask fits nowhere, so n above the width is an
     * empty mask rather than an error.
     */
    public function runs(int $n): self
    {
        if ($n < 1) {
            throw new InvalidArgumentException(sprintf('A run is at least one slot long, not %d.', $n));
        }

        if ($n > $this->width) {
            return self::allClear($this->width);
        }

        $result = $this;
        $covered = 1;

        while ($covered < $n) {
            $step = min($covered, $n - $covered);
            $result = $result->and($result->shiftToLower($step));
            $covered += $step;
        }

        return $result;
    }

    public function isSet(int $index): bool
    {
        if ($index < 0 || $index >= $this->width) {
            throw new InvalidArgumentException(sprintf(
                'A slot index on a mask of width %d lies between 0 and %d, not %d.',
                $this->width,
                $this->width - 1,
                $index,
            ));
        }

        return (($this->words[intdiv($index, self::WORD)] >> ($index % self::WORD)) & 1) === 1;
    }

    /**
     * Every set index, ascending.
     *
     * @return list<int>
     */
    public function setIndices(): array
    {
        $indices = [];

        foreach ($this->words as $position => $word) {
            if ($word === 0) {
                continue;
            }

            $base = $position * self::WORD;

            for ($bit = 0; $bit < self::WORD; $bit++) {
                if ((($word >> $bit) & 1) === 1) {
                    $indices[] = $base + $bit;
                }
            }
        }

        return $indices;
    }

    public function equals(self $other): bool
    {
        return $this->width === $other->width && $this->words === $other->words;
    }

    /**
     * The words the bits sit in, the lowest index in the lowest bit of the first: the stored
     * form of a mask, and exact, since the words are the mask. Whoever stores them stores the
     * width beside them, because a word count does not name a width.
     *
     * @return list<int>
     */
    public function toWords(): array
    {
        return $this->words;
    }

    /**
     * A mask from stored words. The count must be the width's, and no bit past the width may
     * be set: that invariant is what every operation relies on, and a stored word is the one
     * input that could break it.
     *
     * @param  list<int>  $words
     */
    public static function fromWords(int $width, array $words): self
    {
        $count = self::wordsFor($width);

        if (count($words) !== $count) {
            throw new InvalidArgumentException(sprintf('A mask of width %d has %d words, not %d.', $width, $count, count($words)));
        }

        $mask = new self($width, $words);

        if (($words[$count - 1] & ~$mask->topWordMask()) !== 0) {
            throw new InvalidArgumentException(sprintf('A mask of width %d has no bit past its width.', $width));
        }

        return $mask;
    }

    private function paint(int $from, int $to, bool $set): self
    {
        if ($from < 0 || $to > $this->width || $from > $to) {
            throw new InvalidArgumentException(sprintf(
                'A slot range on a mask of width %d is half-open between 0 and %d, not %d to %d.',
                $this->width,
                $this->width,
                $from,
                $to,
            ));
        }

        $words = [];

        foreach ($this->words as $position => $word) {
            $base = $position * self::WORD;

            if ($base < $to && $base + self::WORD > $from) {
                $bits = self::lowBits(min($to, $base + self::WORD) - $base) & ~self::lowBits(max($from, $base) - $base);
                $word = $set ? $word | $bits : $word & ~$bits;
            }

            $words[] = $word;
        }

        return new self($this->width, $words);
    }

    private function assertShift(int $slots): void
    {
        if ($slots < 0) {
            throw new InvalidArgumentException(sprintf('A shift is a number of slots at or above zero, not %d.', $slots));
        }
    }

    private function assertSameWidth(self $other): void
    {
        if ($this->width !== $other->width) {
            throw new InvalidArgumentException(sprintf('Masks of widths %d and %d cannot be combined.', $this->width, $other->width));
        }
    }

    /**
     * The bits of the last word that lie inside the width.
     */
    private function topWordMask(): int
    {
        $used = $this->width % self::WORD;

        return self::lowBits($used === 0 ? self::WORD : $used);
    }

    /**
     * A word shifted down with zeros coming in at the top, for a shift from one to
     * sixty-three: PHP's own shift copies the sign bit down, so the copies are masked off.
     */
    private static function logicalRight(int $word, int $by): int
    {
        return ($word >> $by) & (PHP_INT_MAX >> ($by - 1));
    }

    private static function wordsFor(int $width): int
    {
        if ($width < 1) {
            throw new InvalidArgumentException(sprintf('A mask is at least one slot wide, not %d.', $width));
        }

        return intdiv($width + self::WORD - 1, self::WORD);
    }

    /**
     * A word with its lowest n bits set, for n from zero to sixty-four. Sixty-four is every
     * bit, which is minus one, and the rest come from shifting the largest int down, since
     * shifting one up sixty-three places and subtracting would leave the integers.
     */
    private static function lowBits(int $count): int
    {
        if ($count === 0) {
            return 0;
        }

        if ($count === self::WORD) {
            return -1;
        }

        return PHP_INT_MAX >> (self::WORD - 1 - $count);
    }
}
