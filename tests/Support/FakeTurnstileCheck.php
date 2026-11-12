<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Turnstile\TurnstileCheck;
use SensitiveParameter;

/**
 * The signup form's anti-spam check in a test: scripted to pass or to fail every token it is
 * asked about, keeping each question, the token and the address, so a test can say the check ran
 * once, on what, or not at all. It opens no connection to anywhere and shares nothing with the
 * payment or billing fakes.
 */
final class FakeTurnstileCheck implements TurnstileCheck
{
    /** @var list<array{token: string, remoteAddress: string|null}> */
    public array $asked = [];

    public function __construct(public bool $passing) {}

    public function passes(#[SensitiveParameter] string $token, ?string $remoteAddress): bool
    {
        $this->asked[] = ['token' => $token, 'remoteAddress' => $remoteAddress];

        return $this->passing;
    }
}
