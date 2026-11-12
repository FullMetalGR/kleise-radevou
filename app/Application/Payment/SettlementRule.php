<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Enum\PaymentMode;

/**
 * The one question a transition asks about money: may a booking in this payment mode settle
 * without any having moved?
 *
 * One seam with one method, because there is exactly one decision here and the shape of the
 * answer must not depend on who is answering. It is not the provider port; it is the one
 * question a transition asks of it.
 *
 * Who answers is the tenant's provider, SettlesByProvider: yes for a free and a pay-on-site
 * booking always, and for an online one only while nothing can take the money, which is the
 * degrade rule. A tenant signing up and taking a real booking in the first five minutes is
 * what that rule exists for. Every caller asks the same question whoever the provider is.
 */
interface SettlementRule
{
    public function settlesWithoutPayment(PaymentMode $mode): bool;
}
