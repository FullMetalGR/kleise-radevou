<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * The events that matter, as the adapter reads them from the provider's own event types.
 * Everything else is Other, recorded and acted on by nobody.
 */
enum NoticeKind: string
{
    case CheckoutCompleted = 'checkout_completed';
    case SubscriptionChanged = 'subscription_changed';
    case SubscriptionDeleted = 'subscription_deleted';
    case ChargeFailed = 'charge_failed';
    case ChargeSucceeded = 'charge_succeeded';
    case Other = 'other';
}
