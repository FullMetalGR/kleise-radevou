<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ErrorEnvelope;
use App\Infrastructure\Payment\PaymentWebhook;
use App\Infrastructure\Payment\VerifiedNotice;
use App\Infrastructure\Payment\WebhookAnswer;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The authorization check of the payment webhook, and the declaration that names it: the
 * provider's signature over the raw body, verified by the adapter of the provider in the path
 * with the secret of the connection the token names. No session, no cross-site token, no
 * tenant host, and never the subscription.
 *
 * Fails closed: a provider or token nobody answers to is the envelope's 404, and a signature
 * that does not verify the envelope's 400 invalid_signature, each recorded as refused with
 * nothing of its body. The verified notice is handed to the controller on the request.
 */
final readonly class RequiresPaymentSignature
{
    public const string NAME = 'payment-signature';

    public const string REFUSED_DETAIL = 'The notification does not carry a signature the payment provider vouches for.';

    private const string NOTICE = 'payments.notice';

    public function __construct(private PaymentWebhook $webhook) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $provider = $request->route('provider');
        $token = $request->route('token');

        $verified = $this->webhook->verify(
            is_string($provider) ? $provider : '',
            is_string($token) ? $token : '',
            $request->getContent(),
            fn (string $name): string => $request->headers->get($name) ?? '',
        );

        if ($verified === WebhookAnswer::Unknown) {
            throw new NotFoundHttpException;
        }

        if (! $verified instanceof VerifiedNotice) {
            return (new ErrorEnvelope('invalid_signature', 400, self::REFUSED_DETAIL))->toResponse();
        }

        $request->attributes->set(self::NOTICE, $verified);

        return $next($request);
    }

    /**
     * The notice this check verified for the request.
     */
    public static function noticeOf(Request $request): VerifiedNotice
    {
        $notice = $request->attributes->get(self::NOTICE);

        return $notice instanceof VerifiedNotice ? $notice : throw new LogicException('No payment notice was verified for this request.');
    }
}
