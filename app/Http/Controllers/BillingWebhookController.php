<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\BillingWebhook;
use App\Http\Middleware\RequiresBillingSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /webhooks/billing on the central host, the one door the platform's billing provider
 * knocks on. The signature check in front of it has already verified the body and read it as a
 * notice; this hands the notice on and answers the provider, never a browser, with an empty 200
 * once it has been handled or found to be a repeat.
 */
final class BillingWebhookController extends Controller
{
    public function __invoke(Request $request, BillingWebhook $webhook): Response
    {
        $webhook->receive(RequiresBillingSignature::noticeOf($request), $request->getContent());

        return new Response('', 200);
    }
}
