<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * The booking bundle's sentences as one TypeScript module: lang/en/booking.php and
 * lang/el/booking.php read through the translator's loader, the two tables keyed as the files. Both
 * languages ride inside embed.js, so the switch to the other costs no request; lang/el/booking.php
 * stays the one place the bundle's Greek is written.
 *
 * The module is generated, gitignored and never committed, and never edited by hand: the build
 * scripts run this before vite, and the type check, the component tests and the static analysis
 * before they read it, so each finds it written from lang/ as it stands. It is ASCII, as every file
 * outside lang/el is: each table is written as JSON, every character past ASCII as its \u escape,
 * which a TypeScript string reads back as the character, and the translator's :word placeholders
 * and choice syntax are kept as written, for the bundle's fill() and its mirror of the choice. A
 * Greek file still pending writes no Greek table, so the bundle answers its keys in English, as the
 * translator falls back.
 *
 * The admin's sections are exported the same way under a second target, admin: every file under
 * lang/en/admin, each a section keyed as its file, as one module the admin's component tests alone
 * import, never the admin's build, which receives its sections from the server as the strings prop.
 * The tests build each section's keyed table from it, every value its own key in brackets, so a
 * test never spells a sentence and a new key needs no second spelling, and they hold each section's
 * type to the file's keys. The scripts that run the tests and the type check write it first, as
 * they write the bundle's, so each reads it as lang/ stands.
 */
final class ExportLangCommand extends Command
{
    /** The module the bundle imports, by its path from the repository's root. */
    public const string MODULE = 'resources/js/booking/lines.generated.ts';

    /** The bundle's language file, the one the bundle reads outside the server. */
    public const string FILE = 'booking';

    /** The admin's target: the directory of its sections under lang/en. */
    public const string ADMIN = 'admin';

    /** The module the admin's component tests import, by its path from the repository's root. */
    public const string ADMIN_MODULE = 'resources/js/admin/lines.generated.ts';

    protected $signature = 'lang:export
        {file : What to export: booking, the booking bundle\'s file, or admin, the admin\'s sections}
        {--to= : Where to write the module; the target\'s own path by default}';

    protected $description = 'Write lang/en/booking.php and lang/el/booking.php as the booking bundle\'s generated TypeScript module, or lang/en/admin as the admin tests\' module, ASCII. Run by the build scripts before vite.';

    public function handle(): int
    {
        $file = $this->argument('file');

        if ($file !== self::FILE && $file !== self::ADMIN) {
            $this->error(sprintf('Only %s and %s are exported: the booking bundle and the admin\'s tests are the readers of a language file outside the server.', self::FILE, self::ADMIN));

            return self::INVALID;
        }

        $loader = $this->laravel->make('translation.loader');
        $to = $this->option('to');
        $module = $file === self::FILE ? self::MODULE : self::ADMIN_MODULE;
        $path = is_string($to) && $to !== '' ? $to : base_path($module);

        if ($file === self::FILE) {
            $greek = $loader->load('el', self::FILE);
            $text = self::module($loader->load('en', self::FILE), $greek === [] ? null : $greek);
        } else {
            $text = self::adminModule(array_map(fn (string $section): array => $loader->load('en', self::ADMIN.'/'.$section), self::sections()));
        }

        if (file_put_contents($path, $text) === false) {
            $this->error(sprintf('The module could not be written to %s.', $path));

            return self::FAILURE;
        }

        $this->line(sprintf('Wrote lang/%s to %s.', $file === self::FILE ? '{en,el}/'.self::FILE.'.php' : 'en/'.self::ADMIN, $to === null ? $module : $path));

        return self::SUCCESS;
    }

    /**
     * The admin's sections, each file under lang/en/admin by its name, sorted.
     *
     * @return array<string, string>
     */
    private static function sections(): array
    {
        $sections = [];

        foreach (glob(lang_path('en/'.self::ADMIN.'/*.php')) ?: [] as $path) {
            $name = basename($path, '.php');
            $sections[$name] = $name;
        }

        ksort($sections);

        return $sections;
    }

    /**
     * The admin tests' module: every section's English, keyed as the files.
     *
     * @param  array<string, array<mixed>>  $sections
     */
    private static function adminModule(array $sections): string
    {
        return implode("\n", [
            '/*',
            ' * Generated by php artisan lang:export admin from lang/en/admin. Never edited by hand and',
            ' * never committed: the test and type-check scripts write it again from lang/ before',
            ' * anything reads it. The admin\'s build never imports it.',
            ' */',
            '',
            '/** lang/en/admin, each file a section keyed as the file. */',
            sprintf('export const ADMIN_LINES_EN = %s as const;', self::json($sections)),
            '',
        ]);
    }

    /**
     * The module's text: the English table and the Greek one, null while pending.
     *
     * @param  array<mixed>  $english
     * @param  array<mixed>|null  $greek
     */
    private static function module(array $english, ?array $greek): string
    {
        return implode("\n", [
            '/*',
            ' * Generated by php artisan lang:export booking from lang/en/booking.php and',
            ' * lang/el/booking.php. Never edited by hand and never committed: the build scripts write',
            ' * it again from lang/ before anything reads it.',
            ' */',
            '',
            '/** lang/en/booking.php, keyed as the file. */',
            sprintf('export const BOOKING_LINES_EN = %s as const;', self::json($english)),
            '',
            '/** lang/el/booking.php, keyed as the file, or null while the file is pending. */',
            sprintf('export const BOOKING_LINES_EL: Table | null = %s;', $greek === null ? 'null' : self::json($greek)),
            '',
            'type Table = { readonly [key: string]: string | Table };',
            '',
        ]);
    }

    /**
     * A table as JSON, which a TypeScript module reads as an object literal: every array an
     * object, since a language file's sections are keyed, and every character past ASCII its
     * \u escape, the encoder's default.
     *
     * @param  array<mixed>  $table
     */
    private static function json(array $table): string
    {
        return json_encode($table, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
    }
}
