<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * Where a business stands in its setup: for each step that applies, whether its own data makes it
 * done and whether the owner skipped it, beside whether the wizard ever came up by itself and
 * whether setup was ever finished. Team applies only with the staff feature, so the steps number
 * six with it and five without. A skipped step counts as closed exactly as a done one does, for
 * the count and for finishing; only Team and Online payments can be skipped.
 *
 * Every fact is read fresh on each answer, so a step done once can be open again (the only
 * service deleted, the provider disconnected and not skipped) while setup is unfinished; once
 * finished, the business never reads as being in setup again.
 */
final readonly class SetupProgress
{
    /** The steps in the order the wizard walks them. */
    public const array STEPS = ['profile', 'services', 'team', 'hours', 'payments', 'link'];

    /** The steps that offer "Skip for now". */
    public const array SKIPPABLE = ['team', 'payments'];

    /**
     * @param  array<string, bool>  $done  each step that applies, in order, and whether its own data makes it done
     * @param  array<string, bool>  $skipped  each step that applies, in order, and whether the owner skipped it
     */
    public function __construct(
        public array $done,
        public array $skipped,
        public bool $opened,
        public bool $finished,
    ) {}

    /**
     * The progress from each step's own facts, the team's dropped without the staff feature.
     *
     * @param  array<string, bool>  $done  every step's fact, keyed as STEPS
     * @param  array<string, bool>  $skipped  each skippable step's fact
     */
    public static function of(array $done, array $skipped, bool $staff, bool $opened, bool $finished): self
    {
        $applying = array_values(array_filter(self::STEPS, fn (string $step): bool => $staff || $step !== 'team'));

        return new self(
            array_combine($applying, array_map(fn (string $step): bool => $done[$step] ?? false, $applying)),
            array_combine($applying, array_map(fn (string $step): bool => $skipped[$step] ?? false, $applying)),
            $opened,
            $finished,
        );
    }

    /**
     * The steps that apply, in order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->done);
    }

    public function applies(string $step): bool
    {
        return array_key_exists($step, $this->done);
    }

    /** Whether a step is done by its data or skipped. */
    public function closed(string $step): bool
    {
        return ($this->done[$step] ?? false) || ($this->skipped[$step] ?? false);
    }

    /** Whether every step that applies is done or skipped. */
    public function complete(): bool
    {
        return array_all($this->keys(), fn (string $step): bool => $this->closed($step));
    }

    /** The first step neither done nor skipped, or null when none is. */
    public function firstOpen(): ?string
    {
        return array_find($this->keys(), fn (string $step): bool => ! $this->closed($step));
    }

    /**
     * Each step that applies as the page reads it, in order.
     *
     * @return list<array{key: string, done: bool, skipped: bool}>
     */
    public function steps(): array
    {
        return array_map(fn (string $step): array => ['key' => $step, 'done' => $this->done[$step], 'skipped' => $this->skipped[$step]], $this->keys());
    }
}
