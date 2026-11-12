<?php

declare(strict_types=1);

namespace App\Application\Turnstile;

use SensitiveParameter;

/**
 * The anti-spam check on the signup form: Cloudflare Turnstile's verdict on the token its widget
 * handed the person's browser, asked for the address that posted it, passed or failed. A verdict
 * that never came is a failed check, so no signup goes through on an endpoint that could not be
 * asked. The token is asked about once and kept nowhere. The HTTP client behind it lives under
 * app/Infrastructure, so nothing here imports one, and a test binds a check of its own scripted
 * to pass or fail. It shares nothing with payments or billing.
 */
interface TurnstileCheck
{
    public function passes(#[SensitiveParameter] string $token, ?string $remoteAddress): bool;
}
