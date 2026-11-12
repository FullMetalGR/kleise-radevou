<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment\Stripe;

use App\Application\Payment\Stripe\StripeAnswer;
use App\Application\Payment\Stripe\StripeTransport;
use App\Application\Payment\Stripe\StripeUnreachable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use SensitiveParameter;

/**
 * The payment adapter's transport over the framework's HTTP client: the secret key as the
 * bearer, the parameters form-encoded, and no API version pinned, so the account's own
 * version answers. A test fakes the client and refuses stray requests.
 *
 * Every request gives up after TIMEOUT seconds, connecting included, well inside the database
 * queue's retry_after of 90: the most requests one operation makes is two (a note read back
 * through its session, an expiry read back after a refusal), so a job waiting on Stripe is
 * done or failed before a second worker could take it.
 */
final readonly class HttpStripeTransport implements StripeTransport
{
    public const string BASE = 'https://api.stripe.com';

    public const int TIMEOUT = 15;

    public const int CONNECT_TIMEOUT = 5;

    public function __construct(private Factory $http) {}

    public function send(string $method, string $path, array $form, #[SensitiveParameter] string $secretKey, ?string $idempotencyKey = null, ?string $account = null): StripeAnswer
    {
        $request = $this->http->baseUrl(self::BASE)
            ->withToken($secretKey)
            ->asForm()
            ->acceptJson()
            ->timeout(self::TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT);

        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        if ($account !== null) {
            $request = $request->withHeaders(['Stripe-Account' => $account]);
        }

        try {
            $response = $method === 'GET' ? $request->get($path, $form) : $request->post($path, $form);
        } catch (ConnectionException) {
            throw StripeUnreachable::make();
        }

        $body = $response->json();

        return new StripeAnswer($response->status(), is_array($body) ? $body : []);
    }
}
