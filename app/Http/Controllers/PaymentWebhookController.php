<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\RequiresPaymentSignature;
use App\Infrastructure\Payment\PaymentWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /webhooks/{provider}/{token} on the central host, the door a tenant's own payment
 * provider knocks on. The signature check in front of it has verified the body and routed it to
 * its tenant; this hands the notice on and answers the provider, never a browser, with an empty
 * 200 once it has been handled or found to be a repeat.
 */
final class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentWebhook $webhook): Response
    {
        $webhook->receive(RequiresPaymentSignature::noticeOf($request));

        return new Response('', 200);
    }
}
