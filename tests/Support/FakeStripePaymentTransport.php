<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Payment\Stripe\StripeAnswer;
use App\Application\Payment\Stripe\StripeTransport;
use App\Application\Payment\Stripe\StripeUnreachable;
use LogicException;
use SensitiveParameter;

/**
 * The Stripe payment adapter's transport in a test: it answers only the requests a test told it
 * of, by method and path, each answer used once and in order, and refuses every other request
 * by throwing, so a stray call fails the test that made it. It opens no connection to anywhere.
 *
 * Every request it was asked is kept with its form, its idempotency key and whether the key it
 * carried was the one expected, never the key itself, and, on a platform connection's request,
 * the connected account it acted on. It is the tenants' payments' fake and
 * shares nothing with the platform's billing fake.
 */
final class FakeStripePaymentTransport implements StripeTransport
{
    /** @var list<array{method: string, path: string, form: array<string, scalar>, idempotencyKey: string|null, keyMatched: bool, account?: string}> */
    public array $sent = [];

    /** @var array<string, list<StripeAnswer|null>> by "METHOD path"; a null answer is no answer at all */
    private array $script = [];

    /** The digest of the key a request is expected to carry, so the fake holds no secret a failure could print. */
    private string $expectedKey;

    public function __construct(#[SensitiveParameter] string $expectedKey = '')
    {
        $this->expectedKey = hash('sha256', $expectedKey);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    public function answer(string $method, string $path, int $status, array $body): self
    {
        $this->script[$method.' '.$path][] = new StripeAnswer($status, $body);

        return $this;
    }

    public function unreachable(string $method, string $path): self
    {
        $this->script[$method.' '.$path][] = null;

        return $this;
    }

    public function send(string $method, string $path, array $form, #[SensitiveParameter] string $secretKey, ?string $idempotencyKey = null, ?string $account = null): StripeAnswer
    {
        $sent = ['method' => $method, 'path' => $path, 'form' => $form, 'idempotencyKey' => $idempotencyKey, 'keyMatched' => hash('sha256', $secretKey) === $this->expectedKey];
        $this->sent[] = $account === null ? $sent : [...$sent, 'account' => $account];
        $queue = $this->script[$method.' '.$path] ?? [];

        if ($queue === []) {
            throw new LogicException(sprintf('A stray request to Stripe: %s %s.', $method, $path));
        }

        $answer = array_shift($queue);
        $this->script[$method.' '.$path] = $queue;

        return $answer ?? throw StripeUnreachable::make();
    }
}
