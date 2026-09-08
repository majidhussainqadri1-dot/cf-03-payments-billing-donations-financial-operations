<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

enum FinancialCapability: string
{
    case VIEW_OWN_BILLING = 'finance.view_own_billing';
    case CREATE_DONATION_INTENT = 'finance.create_donation_intent';
    case REQUEST_REFUND = 'finance.request_refund';
    case MANAGE_PRICING = 'finance.manage_pricing';
    case APPROVE_PRICING = 'finance.approve_pricing';
    case OPERATE_PAYMENTS = 'finance.operate_payments';
    case REVIEW_REFUNDS = 'finance.review_refunds';
    case EXECUTE_REFUNDS = 'finance.execute_refunds';
    case REVIEW_FRAUD = 'finance.review_fraud';
    case RECONCILE = 'finance.reconcile';
    case CLOSE_PERIOD = 'finance.close_period';
    case APPROVE_HIGH_RISK = 'finance.approve_high_risk';
    case EXPORT_FINANCE = 'finance.export';
    case VIEW_AUDIT = 'finance.view_audit';
    case MANAGE_PROVIDER_KEYS = 'finance.manage_provider_keys';
}
