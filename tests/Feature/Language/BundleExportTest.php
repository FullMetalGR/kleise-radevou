<?php

use App\Console\Commands\ExportLangCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\TemporaryFiles;

/*
 * The bundle's language export: php artisan lang:export booking reads lang/en/booking.php and
 * lang/el/booking.php through the translator's loader and writes one TypeScript module, ASCII with
 * every character past ASCII as its \u escape, holding the two tables keyed as the files; the Greek
 * is lang/el/booking.php. A pending Greek file writes no Greek table, so the bundle answers its
 * keys in English as the translator does. Each case writes to a temporary file, so no run touches
 * the module the build writes; the module's own path is the one .gitignore names.
 *
 * The admin's target: php artisan lang:export admin writes every file under lang/en/admin, each a
 * section keyed as its file, as the module the admin's component tests build their keyed tables
 * from, ASCII, to a path .gitignore names too.
 */

/**
 * The module the command wrote for the file, to a temporary path.
 */
function exportedModule(string $file = 'booking'): string
{
    $path = TemporaryFiles::make('kr-lines', '.ts');

    expect(Artisan::call('lang:export', ['file' => $file, '--to' => $path]))->toBe(0);

    return File::get($path);
}

/**
 * A table the module holds, decoded back from the JSON it is written as, or null where the module
 * holds none.
 *
 * @return array<mixed>|null
 */
function exportedTable(string $module, string $constant): ?array
{
    $found = preg_match('/^export const '.$constant.'(?:: [^=]+)? = (.*?)(?: as const)?;$/ms', $module, $match);

    expect($found)->toBe(1);

    $table = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);

    if ($table !== null && ! is_array($table)) {
        throw new LogicException(sprintf('The module holds %s as no table.', $constant));
    }

    return $table;
}

/**
 * Points the translator's loader at a language directory of the case's own, holding the real
 * English file and whatever Greek the case writes there, so the real Greek file is out of sight.
 */
function langPathAt(string $root): void
{
    File::ensureDirectoryExists($root.'/en');
    File::copy(lang_path('en/booking.php'), $root.'/en/booking.php');
    app()->useLangPath($root);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');
}

/**
 * Every byte of a text above ASCII, by its offset.
 *
 * @return list<int>
 */
function bytesPastAscii(string $text): array
{
    preg_match_all('/[^\x00-\x7F]/', $text, $matches, PREG_OFFSET_CAPTURE);

    return array_map(fn (array $match): int => $match[1], $matches[0]);
}

it('writes the two files as the module\'s two tables, ASCII, the Greek as escapes that read back as lang/el/booking.php', function () {
    $module = exportedModule();
    $greek = require lang_path('el/booking.php');

    expect(bytesPastAscii($module))->toBe([])
        ->and(exportedTable($module, 'BOOKING_LINES_EN'))->toBe(require lang_path('en/booking.php'))
        ->and(exportedTable($module, 'BOOKING_LINES_EL'))->toBe($greek)
        ->and($module)->toContain(json_encode(__('booking.language', [], 'el'), JSON_THROW_ON_ERROR));
});

it('writes no Greek table while the Greek file is pending, so the bundle answers its keys in English', function () {
    $root = sys_get_temp_dir().'/kr-lang-export-'.Str::random(12);

    try {
        langPathAt($root);

        expect(exportedTable(exportedModule(), 'BOOKING_LINES_EL'))->toBeNull();
    } finally {
        File::deleteDirectory($root);
    }
});

it('writes a Greek file through the loader as escapes, ASCII, that read back as the file, its placeholders and choice syntax kept', function () {
    $root = sys_get_temp_dir().'/kr-lang-export-'.Str::random(12);
    $greek = [
        'language' => "\u{0395}\u{03BB}\u{03BB}\u{03B7}\u{03BD}\u{03B9}\u{03BA}\u{03AC}",
        'duration' => ['hours' => "{1} :count \u{03CE}\u{03C1}\u{03B1}|[2,*] :count \u{03CE}\u{03C1}\u{03B5}\u{03C2}"],
        'quote' => "It's \"quoted\" \\ and slashed / here",
    ];

    try {
        File::ensureDirectoryExists($root.'/el');
        File::put($root.'/el/booking.php', '<?php return '.var_export($greek, true).';');
        langPathAt($root);

        $module = exportedModule();

        expect(bytesPastAscii($module))->toBe([])
            ->and($module)->toContain(json_encode($greek['language'], JSON_THROW_ON_ERROR))
            ->and(exportedTable($module, 'BOOKING_LINES_EL'))->toBe($greek)
            ->and(exportedTable($module, 'BOOKING_LINES_EN'))->toBe(require $root.'/en/booking.php');
    } finally {
        File::deleteDirectory($root);
    }
});

it('refuses a file it does not export and writes nothing', function () {
    $path = TemporaryFiles::make('kr-lines', '.ts');

    expect(Artisan::call('lang:export', ['file' => 'pages', '--to' => $path]))->not->toBe(0)
        ->and(File::exists($path))->toBeFalse()
        ->and(Artisan::output())->toContain('booking');
});

it('writes, by default, to the module the bundle imports, a path .gitignore names so it is never committed', function () {
    $ignored = array_map(trim(...), explode("\n", File::get(base_path('.gitignore'))));

    expect(ExportLangCommand::MODULE)->toBe('resources/js/booking/lines.generated.ts')
        ->and($ignored)->toContain('/'.ExportLangCommand::MODULE);
});

it('writes every file under lang/en/admin as a section of the admin tests\' module, keyed as the file, ASCII', function () {
    $module = exportedModule('admin');
    $sections = [];

    foreach (File::glob(lang_path('en/admin/*.php')) as $path) {
        assert(is_string($path));
        $table = require $path;
        assert(is_array($table));
        $sections[basename($path, '.php')] = $table;
    }

    expect(bytesPastAscii($module))->toBe([])
        ->and($sections)->toHaveKey('shell')
        ->and(exportedTable($module, 'ADMIN_LINES_EN'))->toBe($sections)
        ->and($module)->not->toContain('ADMIN_LINES_EL');
});

it('writes the admin tests\' module, by default, to a path .gitignore names so it is never committed', function () {
    $ignored = array_map(trim(...), explode("\n", File::get(base_path('.gitignore'))));

    expect(ExportLangCommand::ADMIN_MODULE)->toBe('resources/js/admin/lines.generated.ts')
        ->and($ignored)->toContain('/'.ExportLangCommand::ADMIN_MODULE);
});
