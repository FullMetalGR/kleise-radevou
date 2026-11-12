<?php

declare(strict_types=1);

namespace App\Application\Dto;

/**
 * A figure against the period before: the relative change, one rule for every tile, as facts the
 * screen words.
 *
 * Five kinds. up and down carry the signed whole percentage of the change over the figure before,
 * rounded half away from zero, so a change under half a percent is up or down at 0. same is an
 * equal figure, at 0. from_zero is a figure where the period before had none, with no percentage.
 * none is nothing to compare: both figures zero, or either missing (an occupancy with no offered
 * minutes), with no percentage.
 */
final readonly class FigureChange
{
    public const string UP = 'up';

    public const string DOWN = 'down';

    public const string SAME = 'same';

    public const string FROM_ZERO = 'from_zero';

    public const string NONE = 'none';

    private function __construct(public string $kind, public ?int $percent) {}

    public static function between(int|float|null $now, int|float|null $before): self
    {
        if ($now === null || $before === null || ($now == 0 && $before == 0)) {
            return new self(self::NONE, null);
        }

        if ($before == 0) {
            return new self(self::FROM_ZERO, null);
        }

        if ($now == $before) {
            return new self(self::SAME, 0);
        }

        return new self($now > $before ? self::UP : self::DOWN, (int) round(($now - $before) / $before * 100));
    }

    /**
     * @return array{kind: string, percent: int|null}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'percent' => $this->percent];
    }
}
