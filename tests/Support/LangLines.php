<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Site\SiteLanguage;
use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator as TranslatorContract;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Assert;

/**
 * The language files for the Unit suite, which boots no application: a translator built over
 * the repository's lang directory, English its fallback as config/app.php makes it, handed to
 * the templates that read their lines through the container's translator (DecisionLines) and to
 * the test that reads the same lines to compare them. Nothing else is bound, so the suite still
 * boots nothing; release() puts the empty container back after the case, so no translator
 * outlives the file that asked for one.
 */
final class LangLines
{
    /**
     * Binds a translator over lang/ and answers it.
     */
    public static function bind(): Translator
    {
        $translator = new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 2).'/lang'), SiteLanguage::English->value);
        $translator->setFallback(SiteLanguage::English->value);
        Container::getInstance()->instance(TranslatorContract::class, $translator);

        return $translator;
    }

    public static function release(): void
    {
        Container::setInstance(null);
    }

    /**
     * One line of the files in a language, its placeholders filled; a key the files lack fails the
     * case rather than reading as the key.
     *
     * @param  array<string, string>  $replace
     * @return non-empty-string
     */
    public static function line(string $key, array $replace = [], SiteLanguage $language = SiteLanguage::English): string
    {
        $translator = Container::getInstance()->make(TranslatorContract::class);
        $line = $translator->get($key, $replace, $language->value);

        Assert::assertIsString($line);
        Assert::assertNotSame($key, $line, sprintf('%s is no line in %s.', $key, $language->value));

        if ($line === '') {
            Assert::fail(sprintf('%s is an empty line in %s.', $key, $language->value));
        }

        return $line;
    }

    /**
     * One line of the files read as a choice by a number in a language, :count the number, its
     * placeholders filled; a key the files lack fails the case.
     *
     * @param  array<string, string>  $replace
     * @return non-empty-string
     */
    public static function choice(string $key, int $count, array $replace = [], SiteLanguage $language = SiteLanguage::English): string
    {
        $translator = Container::getInstance()->make(TranslatorContract::class);

        Assert::assertNotSame($key, $translator->get($key, [], $language->value), sprintf('%s is no line in %s.', $key, $language->value));

        $line = $translator->choice($key, $count, $replace, $language->value);

        if ($line === '') {
            Assert::fail(sprintf('%s is an empty line in %s.', $key, $language->value));
        }

        return $line;
    }
}
