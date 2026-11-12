<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Site\SiteLanguage;
use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator as TranslatorContract;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Assert;

/**
 * The keyed table for the Unit suite: a translator whose every line is its own key in brackets,
 * the placeholders the English line names after it, so a template rendered through it reads as
 * keys with the values filled in and its own layout around them, and an English sentence left in a
 * class shows as the only words outside a bracket. The PHP twin of SignupForm.test.tsx's table,
 * "[form.title]".
 *
 * Built from lang/en's own files, the admin's sections under admin/ among them (a business mail
 * quotes a label of admin/emails.php), so a key the files lack reads as itself, unbracketed, and a
 * value the template fills names the placeholder the English names, each once. English is its
 * one language and its fallback, so a line asked in Greek reads the same key; the language each
 * line was asked in is kept, asked(), for a case proving which language a template asked for.
 * Nothing else is bound, and release() puts the empty container back, as LangLines does.
 */
final class KeyedLines
{
    private static ?KeyedTranslator $bound = null;

    public static function bind(): Translator
    {
        $loader = new ArrayLoader;

        $root = dirname(__DIR__, 2).'/lang/en/';

        foreach ([...glob($root.'*.php') ?: [], ...glob($root.'admin/*.php') ?: []] as $file) {
            $group = substr($file, strlen($root), -strlen('.php'));
            $lines = require $file;

            Assert::assertIsArray($lines);

            $loader->addMessages(SiteLanguage::English->value, $group, self::keyed($group, $lines));
        }

        $translator = new KeyedTranslator($loader, SiteLanguage::English->value);
        $translator->setFallback(SiteLanguage::English->value);
        Container::getInstance()->instance(TranslatorContract::class, $translator);
        self::$bound = $translator;

        return $translator;
    }

    public static function release(): void
    {
        self::$bound = null;
        Container::setInstance(null);
    }

    /**
     * A line as the table holds it, its values filled in where the English names placeholders:
     * "[customer-mail.greeting Casey Customer]".
     *
     * @return non-empty-string
     */
    public static function line(string $key, string ...$values): string
    {
        return '['.implode(' ', [$key, ...$values]).']';
    }

    /**
     * Every line the bound translator was asked for since it was bound, with the language asked.
     *
     * @return list<array{string, string}>
     */
    public static function asked(): array
    {
        Assert::assertNotNull(self::$bound, 'No keyed table is bound.');

        return self::$bound->asked;
    }

    /**
     * @param  array<mixed>  $lines
     * @return array<string, mixed>
     */
    private static function keyed(string $prefix, array $lines): array
    {
        $keyed = [];

        foreach ($lines as $key => $value) {
            $path = $prefix.'.'.$key;
            $keyed[(string) $key] = is_array($value) ? self::keyed($path, $value) : self::bracketed($path, is_string($value) ? $value : '');
        }

        return $keyed;
    }

    private static function bracketed(string $key, string $english): string
    {
        // A placeholder as LanguageFilesTest reads one: a colon opening a word, not one after a
        // word or another colon.
        preg_match_all('/(?<![A-Za-z0-9_:]):([A-Za-z][A-Za-z0-9_]*)/', $english, $matches);

        // Each placeholder once, in the order the English first names it: a line read as a choice
        // names its placeholders once per case.
        return '['.implode(' ', [$key, ...array_map(fn (string $name): string => ':'.$name, array_values(array_unique($matches[1])))]).']';
    }
}
