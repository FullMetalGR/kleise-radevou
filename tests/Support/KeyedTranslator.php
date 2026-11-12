<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Translation\Translator;

/**
 * KeyedLines' translator, which keeps every line it was asked for and the language it was asked
 * in.
 */
final class KeyedTranslator extends Translator
{
    /** @var list<array{string, string}> */
    public array $asked = [];

    /**
     * @param  string  $key
     * @param  array<mixed>  $replace
     * @param  string|null  $locale
     * @param  bool  $fallback
     * @return string|array<mixed>
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        $this->asked[] = [$key, $locale ?? $this->locale];

        return parent::get($key, $replace, $locale, $fallback);
    }
}
