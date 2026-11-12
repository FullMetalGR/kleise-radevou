<?php

declare(strict_types=1);

namespace App\Site;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Translation\Translator;

/**
 * What frames the legal texts: the entity line, the legal entity behind the platform by its name,
 * its address and, where it has one, its VAT number from site.entity, which each legal page
 * prints and the footer of every page carries; and whether the texts have been reviewed, until
 * which each legal page carries the draft notice at its top.
 *
 * Read from configuration each time it is made, so a changed value changes the line and the
 * notice on the next page served. The line's sentence is site.php's entity in the language asked
 * for, the name and the address filled in, or its entity_vat, the VAT number's clause after them,
 * while site.entity holds a VAT number; an empty or blank one leaves the clause out, so the line
 * never prints a number the entity does not have.
 *
 * The texts count as reviewed only when site.legal_reviewed is on and no legal file of either
 * language still holds a gap, a fact in square brackets waiting to be confirmed: a flag turned on
 * over a text with a gap left in it would publish the gap as the reviewed text. The check reads
 * the files through the translator, which loads each once per process, and only when the flag is
 * on; with it off no file is read.
 */
final readonly class LegalFrame
{
    /** The legal files of each language, as the translator names them. */
    public const array FILES = ['legal/terms', 'legal/privacy', 'legal/cookies', 'legal/dpa'];

    /**
     * What closes a gap in a legal file, in each language's words: "to be confirmed]" in English
     * and its Greek twin, the escapes keeping this file ASCII.
     */
    public const array GAPS = [
        'to be confirmed]',
        "\u{03C0}\u{03C1}\u{03BF}\u{03C2} \u{03B5}\u{03C0}\u{03B9}\u{03B2}\u{03B5}\u{03B2}\u{03B1}\u{03AF}\u{03C9}\u{03C3}\u{03B7}]",
    ];

    public function __construct(
        private Translator $translator,
        #[Config('site.entity.name')] private string $name,
        #[Config('site.entity.address')] private string $address,
        #[Config('site.entity.vat')] private string $vat,
        #[Config('site.legal_reviewed')] private bool $flagged,
    ) {}

    /**
     * The entity line in a language.
     */
    public function entity(SiteLanguage $language): string
    {
        $vat = trim($this->vat);
        $key = $vat === '' ? 'site.entity' : 'site.entity_vat';
        $line = $this->translator->get($key, ['entity' => $this->name, 'address' => $this->address, 'vat' => $vat], $language->value);

        return is_string($line) ? $line : $key;
    }

    /**
     * Whether the legal texts count as reviewed: the flag on and no gap left in any legal file of
     * either language.
     */
    public function reviewed(): bool
    {
        if (! $this->flagged) {
            return false;
        }

        foreach (SiteLanguage::cases() as $language) {
            foreach (self::FILES as $file) {
                if (self::holdsGap($this->translator->get($file, [], $language->value))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether a file's lines, or any list or section nested in them, hold a gap. A file the
     * translator cannot read as lines holds none it could print either.
     */
    private static function holdsGap(mixed $lines): bool
    {
        if (is_string($lines)) {
            return str_replace(self::GAPS, '', $lines) !== $lines;
        }

        if (! is_array($lines)) {
            return false;
        }

        foreach ($lines as $line) {
            if (self::holdsGap($line)) {
                return true;
            }
        }

        return false;
    }
}
