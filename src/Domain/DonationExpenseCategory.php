<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;

final class DonationExpenseCategory
{
    public const INSTITUTIONAL_OPERATIONS = 'institutional_operations';
    public const TECHNICAL_DEVELOPMENT = 'technical_development';
    public const HOMEOPATHY_ADVANCEMENT = 'homeopathy_advancement';
    public const ADMINISTRATION_PAYMENT_CHARGES = 'administration_payment_charges';
    public const FOUNDER_COMPENSATION = 'founder_compensation';
    public const FOUNDER_EXPENSE_REIMBURSEMENT = 'founder_expense_reimbursement';
    public const FOUNDER_ADVANCE_REPAYMENT = 'founder_advance_repayment';
    public const OWNER_WITHDRAWAL = 'owner_withdrawal';

    /** @return list<string> */
    public static function allowed(): array
    {
        return [self::INSTITUTIONAL_OPERATIONS,self::TECHNICAL_DEVELOPMENT,self::HOMEOPATHY_ADVANCEMENT,self::ADMINISTRATION_PAYMENT_CHARGES,self::FOUNDER_COMPENSATION,self::FOUNDER_EXPENSE_REIMBURSEMENT,self::FOUNDER_ADVANCE_REPAYMENT,self::OWNER_WITHDRAWAL];
    }

    /** @return list<string> */
    public static function founderRelated(): array
    {
        return [self::FOUNDER_COMPENSATION,self::FOUNDER_EXPENSE_REIMBURSEMENT,self::FOUNDER_ADVANCE_REPAYMENT,self::OWNER_WITHDRAWAL];
    }

    public static function assertAllowed(string $category): void
    {
        if (! in_array($category,self::allowed(),true)) { throw new InvalidArgumentException('Donation expense category is not approved by the Founder policy.'); }
    }

    public static function isFounderRelated(string $category): bool
    {
        self::assertAllowed($category); return in_array($category,self::founderRelated(),true);
    }
}
