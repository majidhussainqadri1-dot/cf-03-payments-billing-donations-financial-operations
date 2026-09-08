<?php

declare(strict_types=1);

namespace Sabri\CF03\Integration;

enum FinancialFactType: string
{
    case PAYMENT_SETTLED = 'PaymentSettled';
    case PAYMENT_FAILED = 'PaymentFailed';
    case PAYMENT_CANCELLED = 'PaymentCancelled';
    case REFUND_SETTLED = 'RefundSettled';
    case SUBSCRIPTION_CANCELLED = 'SubscriptionCancelled';
}
