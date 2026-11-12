<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * One field of an own-mode connection as its provider declares it: the name it is stored under,
 * what the tenant reads beside it, and whether it is a secret. The Payments settings form is
 * rendered from these, so a provider with four fields where another has two is a declaration
 * and never a screen change. The label and the help are keys of our words for the field, read
 * in the request's language where the screen and the refusals are built; words with no key
 * under them read as they are.
 */
final readonly class CredentialField
{
    public function __construct(
        public string $name,
        public string $label,
        public string $help,
        public bool $secret,
    ) {}
}
