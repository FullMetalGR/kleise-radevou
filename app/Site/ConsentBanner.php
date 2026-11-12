<?php

declare(strict_types=1);

namespace App\Site;

/**
 * What the consent banner and the footer's cookie-settings control render, in one page's
 * language: the analytics tag's measurement id and script address, which the banner carries for
 * the consent script to load from once a visitor accepts; the cookie notice's address, which the
 * banner links and the control is a link to where no script runs; and the banner's sentences,
 * every one of them in the markup, since the script holds none.
 */
final readonly class ConsentBanner
{
    public function __construct(
        public string $measurementId,
        public string $script,
        public string $notice,
        public string $label,
        public string $sentence,
        public string $noticeLabel,
        public string $accept,
        public string $reject,
        public string $settings,
    ) {}
}
