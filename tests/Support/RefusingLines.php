<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Translation\Translator as Lines;
use RuntimeException;

/**
 * A translator that cannot read some lines, so a test can make a message's words fail to be
 * written: every key under one of the prefixes it refuses throws, its exception's message quoting
 * the values the line would have been filled with, which is what a log line must never repeat;
 * every other key is the translator it wraps. Bound under the contract the templates' line readers
 * ask the container for, CustomerMailLines and DecisionLines, and nothing else is touched. It
 * answers the two further reads CustomerMailLines::spelledChoice makes of the translator, whether
 * a language holds a line and the fallback, the first refusing as get() does.
 */
final class RefusingLines implements Translator
{
    /**
     * @param  list<string>  $refused  the key prefixes it cannot read
     */
    public function __construct(
        private readonly Lines $lines,
        private readonly array $refused,
    ) {}

    /**
     * @param  string  $key
     * @param  array<mixed>  $replace
     * @param  string|null  $locale
     */
    public function get($key, array $replace = [], $locale = null)
    {
        $this->refuse($key, $replace);

        return $this->lines->get($key, $replace, $locale);
    }

    /**
     * @param  string  $key
     * @param  \Countable|int|float|array<mixed>  $number
     * @param  array<mixed>  $replace
     * @param  string|null  $locale
     */
    public function choice($key, $number, array $replace = [], $locale = null)
    {
        $this->refuse($key, $replace);

        return $this->lines->choice($key, $number, $replace, $locale);
    }

    /**
     * @param  string  $key
     * @param  string|null  $locale
     */
    public function hasForLocale($key, $locale = null): bool
    {
        $this->refuse($key, []);

        return $this->lines->hasForLocale($key, $locale);
    }

    public function getFallback(): string
    {
        return $this->lines->getFallback();
    }

    public function getLocale()
    {
        return $this->lines->getLocale();
    }

    /**
     * @param  string  $locale
     */
    public function setLocale($locale)
    {
        $this->lines->setLocale($locale);
    }

    /**
     * @param  array<mixed>  $replace
     */
    private function refuse(string $key, array $replace): void
    {
        foreach ($this->refused as $prefix) {
            if (str_starts_with($key, $prefix)) {
                throw new RuntimeException(sprintf('The line %s could not be read for %s.', $key, json_encode($replace)));
            }
        }
    }
}
