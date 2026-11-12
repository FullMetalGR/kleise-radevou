<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Site\SiteLanguage;
use LogicException;

/**
 * What a click on a signup's verification link found: the slug its row holds, the language the
 * signup was made in, which the click sends on in, and whether this click built it or found it
 * built already, by an earlier click of the link or one racing it. A click that built carries the
 * trial as the build left it, re-read after the commit, for the welcome email it sends; a click
 * that built nothing carries none, so no second click can send a second welcome.
 */
final readonly class VerifiedSignup
{
    public function __construct(
        public Slug $slug,
        public SiteLanguage $locale,
        public bool $built,
        public ?BuiltTrial $trial = null,
    ) {
        if ($built !== ($trial !== null)) {
            throw new LogicException('A click that built carries the trial it built, and a click that built nothing carries none.');
        }
    }
}
