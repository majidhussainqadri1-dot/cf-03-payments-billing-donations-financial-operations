<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use Sabri\CF03\Support\InvariantViolation;

/**
 * Compatibility tombstone for superseded paid-AI usage authorization.
 *
 * Approved Sabri Classical Homeopathy AI is a free core capability. CF-03 must
 * not meter, sell, charge, financially authorize or entitlement-gate AI usage.
 */
final class AiUsageAuthorization
{
    public function __construct(
        string $authorizationId,
        string $actorReference,
        string $productId,
        string|Money $priceVersionId,
        int $maximumUnits,
        Money $hardCap,
        DateTimeImmutable $validUntil,
        ?string $legacyEvidenceHash = null
    ) {
        throw new InvariantViolation(
            'Paid AI usage authorization is retired; approved Sabri AI is a free core capability.'
        );
    }

    public function authorizationId(): string
    { throw new InvariantViolation('Paid AI usage authorization is retired.'); }

    public function actorReference(): string
    { throw new InvariantViolation('Paid AI usage authorization is retired.'); }

    public function productId(): string
    { throw new InvariantViolation('Paid AI usage authorization is retired.'); }

    public function priceVersionId(): string
    { throw new InvariantViolation('Paid AI usage pricing is retired.'); }

    public function maximumUnits(): int
    { throw new InvariantViolation('Financial AI usage limits are retired.'); }

    public function hardCap(): Money
    { throw new InvariantViolation('Financial AI usage hard caps are retired.'); }

    public function validUntil(): DateTimeImmutable
    { throw new InvariantViolation('Paid AI usage authorization is retired.'); }

    public function assertSignedUsage(
        string $usageId,
        string $producerReference,
        int $units,
        DateTimeImmutable $occurredAt,
        string $signatureHex,
        string $sharedSecret
    ): void {
        throw new InvariantViolation('Financial AI usage metering is retired.');
    }

    public function assertSignedUsageFact(
        string $usageId,
        int $units,
        DateTimeImmutable $occurredAt,
        string $signatureHex,
        string $sharedSecret
    ): void {
        throw new InvariantViolation('Historical financial AI usage facts cannot authorize current charging.');
    }

    public function priceFor(int $units, DateTimeImmutable $occurredAt): Money
    {
        throw new InvariantViolation('AI usage pricing is retired under the free-core constitution.');
    }
}
