<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class PriceCatalog
{
    /** @var list<PriceVersion> */
    private readonly array $versions;

    /** @param list<PriceVersion> $versions */
    public function __construct(private readonly FinancialProduct $product, array $versions)
    {
        if ($product->kind() === ProductKind::DONATION) {
            throw new InvalidArgumentException('Donation products do not use a fixed price catalog.');
        }

        $seen = [];
        foreach ($versions as $version) {
            if (! $version instanceof PriceVersion) {
                throw new InvalidArgumentException('Every catalog item must be a PriceVersion.');
            }
            $version->assertForProduct($product);
            if (isset($seen[$version->versionId()])) {
                throw new InvariantViolation('Duplicate price version ID.');
            }
            $seen[$version->versionId()] = true;
        }

        $this->versions = array_values($versions);
        $this->assertNoApprovedOverlap();
    }

    public function resolve(DateTimeImmutable $at, string $region, string $currency): PriceVersion
    {
        if (! $this->product->isCheckoutEligible()) {
            throw new InvariantViolation('Product is not approved and available for checkout.');
        }

        $currency = strtoupper($currency);
        $region = strtoupper($region);
        $exact = $this->matching($at, $region, $currency);
        $matches = $exact !== [] ? $exact : $this->matching($at, 'GLOBAL', $currency);

        if (count($matches) !== 1) {
            throw new InvariantViolation('Exactly one approved effective price snapshot is required.');
        }

        return $matches[0];
    }

    public function historicalApprovedVersion(string $versionId): PriceVersion
    {
        foreach ($this->versions as $version) {
            if ($version->versionId() === $versionId && $version->approved()) {
                return $version;
            }
        }

        throw new InvariantViolation('Approved historical price version was not found.');
    }

    /** @return list<PriceVersion> */
    private function matching(DateTimeImmutable $at, string $region, string $currency): array
    {
        return array_values(array_filter(
            $this->versions,
            static fn (PriceVersion $version): bool => $version->region() === $region
                && $version->amount()->currency() === $currency
                && $version->isEffectiveAt($at)
        ));
    }

    private function assertNoApprovedOverlap(): void
    {
        $count = count($this->versions);
        for ($left = 0; $left < $count; $left++) {
            $a = $this->versions[$left];
            if (! $a->approved()) {
                continue;
            }
            for ($right = $left + 1; $right < $count; $right++) {
                $b = $this->versions[$right];
                if (! $b->approved()
                    || $a->region() !== $b->region()
                    || $a->amount()->currency() !== $b->amount()->currency()
                ) {
                    continue;
                }

                $aEndsAfterBStarts = $a->effectiveUntil() === null
                    || $a->effectiveUntil() > $b->effectiveFrom();
                $bEndsAfterAStarts = $b->effectiveUntil() === null
                    || $b->effectiveUntil() > $a->effectiveFrom();

                if ($aEndsAfterBStarts && $bEndsAfterAStarts) {
                    throw new InvariantViolation('Approved price versions overlap for the same region and currency.');
                }
            }
        }
    }
}
