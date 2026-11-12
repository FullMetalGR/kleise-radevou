<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ErrorEnvelope;
use App\Infrastructure\Payment\PlatformWebhook;
use App\Infrastructure\Payment\VerifiedPlatformNotice;
use App\Infrastructure\Payment\WebhookAnswer;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The platform payment webhook's access check: the provider registered with the platform's keys
 * set, or 404; the signature vouched for by the platform's own signing secret, or the
 * envelope's 400, both recorded by PlatformWebhook.
 */
final readonly class RequiresPlatformSignature
{
    public const string NAME = 'platform-signature';

    private const string NOTICE = 'payments.platform_notice';

    public function __construct(private PlatformWebhook $webhook) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $provider = $request->route('provider');
        $verified = $this->webhook->verify(
            is_string($provider) ? $provider : '',
            $request->getContent(),
            fn (string $name): string => $request->headers->get($name) ?? '',
        );
        if ($verified === WebhookAnswer::Unknown) {
            throw new NotFoundHttpException;
        }
        if (! $verified instanceof VerifiedPlatformNotice) {
            return (new ErrorEnvelope('invalid_signature', 400, RequiresPaymentSignature::REFUSED_DETAIL))->toResponse();
        }
        $request->attributes->set(self::NOTICE, $verified);

        return $next($request);
    }

    public static function noticeOf(Request $request): VerifiedPlatformNotice
    {
        $notice = $request->attributes->get(self::NOTICE);

        return $notice instanceof VerifiedPlatformNotice ? $notice : throw new LogicException('No platform notice was verified for this request.');
    }
}
