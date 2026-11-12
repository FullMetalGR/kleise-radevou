<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\RequiresPlatformSignature;
use App\Infrastructure\Payment\PlatformWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /webhooks/{provider}, platform mode's shape: an empty 200 for an update landed,
 * repeated, or about an account no connection holds.
 */
final class PlatformWebhookController extends Controller
{
    public function __invoke(Request $request, PlatformWebhook $webhook): Response
    {
        $webhook->receive(RequiresPlatformSignature::noticeOf($request));

        return new Response('', 200);
    }
}
