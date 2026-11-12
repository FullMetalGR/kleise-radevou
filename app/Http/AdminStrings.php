<?php

declare(strict_types=1);

namespace App\Http;

use App\Site\SiteLanguage;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;

/**
 * The admin's sentences on their way to the screen: each file under lang/{en,el}/admin is a
 * section a page receives by name, read through the translator in the request's language, which
 * SetTenantLocale set to the user's, so the sections and every flash, refusal and validation line
 * the server words agree. The signup's SignupStrings is the precedent.
 *
 * The strings prop holds the shell's section on every page, the sign-in page's included, and the
 * further sections a page's controller names through need(), each joining the shell's beside it
 * and never replacing it: the controller names them on the request, and the prop, a closure the
 * shared props resolve when the page renders, reads them there. A sentence with a value keeps the
 * translator's :name placeholder, which the page fills through fill() of booking/placeholders.
 *
 * The console's door and shell render the shared kit, so the console's adapter hands the shell's
 * section read with the locale fixed to English; the central host's pages render the toaster and
 * the controls alone, so its adapter hands those two groups, in the request's language, under a
 * name signup.php does not hold.
 */
final readonly class AdminStrings
{
    /** The directory under lang/{locale} the sections are read from, and their keys' prefix. */
    public const string DIRECTORY = 'admin';

    /** The section every page carries. */
    public const string SHELL = 'shell';

    /** The groups of the shell's section a page outside the admin and the console renders. */
    public const array KIT = ['toaster', 'kit'];

    /** The request attribute holding the further sections a page's controller named. */
    public const string NAMED = 'admin.strings.named';

    public function __construct(private Translator $translator) {}

    /**
     * Names the further sections the page answering this request reads, beside the shell's.
     */
    public static function need(Request $request, string ...$sections): void
    {
        $request->attributes->set(self::NAMED, array_values(array_unique([...self::named($request), ...$sections])));
    }

    /**
     * The further sections named on the request so far.
     *
     * @return list<string>
     */
    private static function named(Request $request): array
    {
        $named = $request->attributes->get(self::NAMED);

        return is_array($named) ? array_values(array_filter($named, is_string(...))) : [];
    }

    /**
     * The strings prop of the page answering the request: the shell's section and every section
     * its controller named, each in the request's language.
     *
     * @return array<string, array<mixed>>
     */
    public function for(Request $request): array
    {
        $strings = [self::SHELL => $this->section(self::SHELL)];

        foreach (self::named($request) as $section) {
            $strings[$section] = $this->section($section);
        }

        return $strings;
    }

    /**
     * The strings prop with the shell's section alone, in the language given, else the
     * application's locale: a page answered before the shared props are, past a limit.
     *
     * @return array{shell: array<mixed>}
     */
    public function shell(?SiteLanguage $language = null): array
    {
        return [self::SHELL => $this->section(self::SHELL, $language)];
    }

    /**
     * The groups of the shell's section a central page renders, in the request's language.
     *
     * @return array{shell: array<mixed>}
     */
    public function kit(): array
    {
        return [self::SHELL => array_intersect_key($this->section(self::SHELL), array_flip(self::KIT))];
    }

    /**
     * One section, every string of lang/{locale}/admin/{name}.php, the placeholders unfilled:
     * in the language given, else the application's locale.
     *
     * @return array<mixed>
     */
    public function section(string $name, ?SiteLanguage $language = null): array
    {
        $strings = $this->translator->get(self::DIRECTORY.'/'.$name, [], $language?->value);

        return is_array($strings) ? $strings : [];
    }
}
