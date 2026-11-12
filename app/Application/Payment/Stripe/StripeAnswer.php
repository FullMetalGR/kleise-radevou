<?php

declare(strict_types=1);

namespace App\Application\Payment\Stripe;

/**
 * What Stripe answered one request: its HTTP status and its body decoded, an empty array for a
 * body that is not a JSON object.
 */
final readonly class StripeAnswer
{
    /**
     * @param  array<array-key, mixed>  $body
     */
    public function __construct(
        public int $status,
        public array $body,
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Stripe's own sentence for an error answer, or the status where it gave none.
     */
    public function reason(): string
    {
        return $this->message() ?? sprintf('Stripe answered HTTP %d.', $this->status);
    }

    /**
     * Stripe's own sentence for an error answer, null where it gave none.
     */
    public function message(): ?string
    {
        $error = $this->body['error'] ?? null;
        $message = is_array($error) ? ($error['message'] ?? null) : null;

        return is_string($message) && $message !== '' ? $message : null;
    }
}
