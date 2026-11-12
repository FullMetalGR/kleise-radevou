<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Enum\PaymentMode;

/**
 * The settlement question answered by the tenant's provider. A free booking and a pay-on-site
 * one settle with nothing paid, always: cash is never a connection. An online one settles
 * unpaid only while nothing can take the money, which is the degrade rule: with no working
 * connection an online service behaves as an on-site one. While a provider is available the
 * answer is no, and the confirm is refused with payment_required.
 *
 * It asks the port whether a connection works and nothing more, and the port answers that from
 * the stored row, so the question costs no call to anybody.
 */
final readonly class SettlesByProvider implements SettlementRule
{
    public function __construct(private PaymentProvider $provider) {}

    public function settlesWithoutPayment(PaymentMode $mode): bool
    {
        return match ($mode) {
            PaymentMode::Free, PaymentMode::Onsite => true,
            PaymentMode::Online => ! $this->provider->isAvailable(),
        };
    }
}
