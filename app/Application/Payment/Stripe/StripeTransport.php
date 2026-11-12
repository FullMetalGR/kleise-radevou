<?php

declare(strict_types=1);

namespace App\Application\Payment\Stripe;

use SensitiveParameter;

/**
 * How the Stripe adapter reaches Stripe's API: one form-encoded request with the connection's
 * secret key as its bearer, answered with the status and the decoded body. The HTTP client
 * behind it lives under app/Infrastructure, so nothing here imports one, and a test hands the
 * adapter a transport of its own that refuses every request it was not told of.
 *
 * Every request ends well inside the database queue's retry_after, so a job waiting on Stripe
 * is never picked up by a second worker while the first still waits.
 */
interface StripeTransport
{
    /**
     * @param  'GET'|'POST'  $method
     * @param  string  $path  from the API's root, as /v1/checkout/sessions
     * @param  array<string, scalar>  $form  the parameters, already flattened to Stripe's bracketed names
     * @param  string|null  $idempotencyKey  sent on a POST that must answer the same object twice
     * @param  string|null  $account  the connected account the request acts on, as Stripe-Account, for a platform connection
     *
     * @throws StripeUnreachable when no answer arrived, a timeout included
     */
    public function send(string $method, string $path, array $form, #[SensitiveParameter] string $secretKey, ?string $idempotencyKey = null, ?string $account = null): StripeAnswer;
}
