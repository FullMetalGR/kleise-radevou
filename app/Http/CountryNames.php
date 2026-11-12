<?php

declare(strict_types=1);

namespace App\Http;

use App\Site\SiteLanguage;
use Illuminate\Contracts\Translation\Translator;

/**
 * The country names of lang/{en,el}/countries.php by code: the server's one reader of the file,
 * through the translator, so a name is never read from ICU. The business page prints the stored
 * country's name from it in the request's language, and the Settings screen's select offers its
 * codes with their names in the user's; the keys are the codes as the settings row stores them,
 * in the file's order.
 */
final readonly class CountryNames
{
    /** The file under lang/{locale}, and its translator key. */
    private const string FILE = 'countries';

    public function __construct(private Translator $translator) {}

    /**
     * Every name by its code, in the language given, else the application's locale, the
     * request's.
     *
     * @return array<string, string>
     */
    public function in(?SiteLanguage $language = null): array
    {
        $names = $this->translator->get(self::FILE, [], $language?->value);
        $countries = [];

        foreach (is_array($names) ? $names : [] as $code => $name) {
            if (is_string($name)) {
                $countries[(string) $code] = $name;
            }
        }

        return $countries;
    }
}
