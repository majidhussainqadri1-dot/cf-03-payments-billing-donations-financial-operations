<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class PlatformFinancialPolicy
{
    public const DECISION_ID = 'SSH-FIN-2026-08-04-01';
    public const EFFECTIVE_AT = '2026-08-04T11:39:00+05:00';
    public const MODE = 'free_donation_only';

    /** @return list<Money> */
    public function suggestedDonationAmounts(): array
    {
        return [new Money(1000, 'USD'), new Money(1400, 'USD'), new Money(5000, 'USD')];
    }

    public function defaultDonationAmount(): ?Money
    {
        return null;
    }

    public function defaultRecurringDonation(): bool
    {
        return false;
    }

    public function paidServicesSuspended(): bool
    {
        return true;
    }

    public function platformCommissionBasisPoints(): int
    {
        return 0;
    }

    public function assertCollectibleProduct(FinancialProduct $product): void
    {
        if ($product->kind() !== ProductKind::DONATION) {
            throw new InvariantViolation(
                'Paid membership, education, AI and platform-service products are dormant under ' . self::DECISION_ID . '.'
            );
        }
    }

    public function assertNoPlatformFee(int $basisPoints): void
    {
        if ($basisPoints !== 0) {
            throw new InvariantViolation('Platform fees and commissions must remain 0% while the free-service policy is active.');
        }
    }

    public function assertSuggestedOrCustomDonation(Money $amount): void
    {
        if ($amount->currency() !== 'USD' || $amount->minorUnits() <= 0) {
            throw new InvalidArgumentException('Donation amount must be a positive USD amount.');
        }
    }
}
