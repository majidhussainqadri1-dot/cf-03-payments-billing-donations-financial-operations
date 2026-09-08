<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class CommissionPolicy
{
    public const DOMAIN_CLINIC = 'clinic';
    public const DOMAIN_MARKETPLACE = 'marketplace';

    public function assertConfiguredBasisPoints(string $domain, int $basisPoints): void
    {
        $this->assertConstitutionalDomain($domain);

        if ($basisPoints !== 0) {
            throw new InvariantViolation('Clinic and Marketplace platform commission must remain 0%.');
        }
    }

    public function platformCommission(string $domain, Money $gross): Money
    {
        $this->assertConstitutionalDomain($domain);
        return Money::zero($gross->currency());
    }

    private function assertConstitutionalDomain(string $domain): void
    {
        if (! in_array($domain, [self::DOMAIN_CLINIC, self::DOMAIN_MARKETPLACE], true)) {
            throw new InvalidArgumentException('Unknown zero-commission domain.');
        }
    }
}
