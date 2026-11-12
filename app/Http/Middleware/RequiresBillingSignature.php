<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Billing\BillingNotice;
use App\Billing\BillingProvider;
use App\Billing\SignatureRefused;
use App\Http\ErrorEnvelope;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The authorization check of the billing webhook, and the declaration that names it: the
 * billing provider's signature over the raw body, verified by the port, and nothing else. The
 * webhook has no session, no cross-site token and no tenant, and the subscription gates it
 * never.
 *
 * Verification fails closed: a notification the port refuses answers 400 with the envelope,
 * leaves one log line naming the port's reason, and is stored nowhere. The notice the port read
 * is handed to the controller on the request, so nothing behind this reads the body a second
 * way.
 */
final readonly class RequiresBillingSignature
{
    public const string NAME = 'billing-signature';

    public const string REFUSED_DETAIL = 'The notification does not carry a signature the billing provider vouches for.';

    public const string REFUSED_LOG = 'The billing webhook refused a notification its signature does not vouch for';

    private const string NOTICE = 'billing.notice';

    public function __construct(
        private BillingProvider $provider,
        private LoggerInterface $log,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $notice = $this->provider->verify($request->getContent(), $request->headers->get($this->provider->signatureHeader()) ?? '');
        } catch (SignatureRefused $refused) {
            $this->log->warning(self::REFUSED_LOG, ['reason' => $refused->getMessage()]);

            return (new ErrorEnvelope('invalid_signature', 400, self::REFUSED_DETAIL))->toResponse();
        }

        $request->attributes->set(self::NOTICE, $notice);

        return $next($request);
    }

    /**
     * The notice this check verified for the request.
     */
    public static function noticeOf(Request $request): BillingNotice
    {
        $notice = $request->attributes->get(self::NOTICE);

        return $notice instanceof BillingNotice ? $notice : throw new LogicException('No billing notice was verified for this request.');
    }
}
