<?php

declare(strict_types=1);

namespace App\Site;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Translation\Translator;

/**
 * The site's one tag, Google Analytics behind consent: the banner a central page carries, or
 * none. While the measurement id is unset there is no banner, no loader and no cookie-settings
 * control, and nothing is asked; an empty variable reads as unset, and so does an empty script
 * address, since there would be nothing to load. Only the central host's two root views are
 * handed one, the site's layout and the signup's frame, through the chrome; no tenant host's view
 * and not the console's ever is.
 *
 * Read from configuration each time it is made, so a changed id changes every page served next.
 * The sentences are site.php's consent in the page's language, :name filled with the public name.
 */
final readonly class Analytics
{
    public function __construct(
        private Translator $translator,
        private SitePages $pages,
        private Brand $brand,
        #[Config('services.google_analytics.measurement_id')] private ?string $measurementId,
        #[Config('services.google_analytics.script')] private ?string $script,
    ) {}

    public function banner(SiteLanguage $language): ?ConsentBanner
    {
        if ($this->measurementId === null || $this->measurementId === '' || $this->script === null || $this->script === '') {
            return null;
        }

        return new ConsentBanner(
            measurementId: $this->measurementId,
            script: $this->script,
            notice: $this->pages->url(SitePages::COOKIES, $language),
            label: $this->text('label', $language),
            sentence: $this->text('sentence', $language),
            noticeLabel: $this->text('notice', $language),
            accept: $this->text('accept', $language),
            reject: $this->text('reject', $language),
            settings: $this->text('settings', $language),
        );
    }

    private function text(string $key, SiteLanguage $language): string
    {
        $text = $this->translator->get('site.consent.'.$key, ['name' => $this->brand->name], $language->value);

        return is_string($text) ? $text : 'site.consent.'.$key;
    }
}
