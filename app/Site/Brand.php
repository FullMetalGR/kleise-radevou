<?php

declare(strict_types=1);

namespace App\Site;

use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\HtmlString;

/**
 * The provisional brand as the server hands it on: the public name, config('site.name'), and the
 * wordmark's mark.
 *
 * The mark has one source, the favicon under public/, which is SVG markup: the site's layout
 * inlines it from here, the admin bundle imports the same file as text, and the browser shows it
 * as the platform's icon. The name is never compiled into the bundle: every Inertia adapter shares
 * it as the brand prop, and the React shell's wordmark and the title template read it from there.
 */
final readonly class Brand
{
    /** The mark's one source, under public/: the favicon itself. */
    public const string MARK = 'favicon.svg';

    /** The tokens file, under resources/: with the mark, the one place the brand's values change. */
    public const string TOKENS = 'js/brand/brand.css';

    public function __construct(#[Config('site.name')] public string $name) {}

    /**
     * The brand prop every adapter shares.
     *
     * @return array{name: string}
     */
    public function prop(): array
    {
        return ['name' => $this->name];
    }

    /**
     * The mark's markup, inlined where the layout draws the wordmark.
     */
    public function mark(): HtmlString
    {
        return new HtmlString(trim(File::get(public_path(self::MARK))));
    }

    /**
     * The tokens' values by name, as the tokens file spells them: every --brand-{name} custom
     * property of its root block, in the file's order, the colours and the type. The platform's
     * mails inline them, since a mail reads no stylesheet any more than the favicon does, and
     * reading the file keeps it the one place the values change.
     *
     * @return array<string, string>
     */
    public function tokens(): array
    {
        preg_match_all('/^\s*--brand-([a-z-]+):\s*([^;]+);/m', File::get(resource_path(self::TOKENS)), $matches, PREG_SET_ORDER);

        $tokens = [];

        foreach ($matches as $match) {
            $tokens[$match[1]] = trim($match[2]);
        }

        return $tokens;
    }
}
