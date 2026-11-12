<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Site\TermsVersion;
use InvalidArgumentException;

/**
 * The owner's acceptance of the terms: the version of the terms the signup form's box accepted,
 * the day the terms file's version line names, as Y-m-d. The reservation writes it on the row
 * beside the instant, the clock's now as the row's created_at; no other caller of Signup carries
 * one, so the console's rows and the demo's hold none.
 */
final readonly class TermsAcceptance
{
    /**
     * @throws InvalidArgumentException when the version is no Y-m-d day
     */
    public function __construct(public string $version)
    {
        TermsVersion::dayFromStored($version);
    }
}
