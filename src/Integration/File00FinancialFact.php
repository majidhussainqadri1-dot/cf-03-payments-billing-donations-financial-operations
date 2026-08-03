<?php

declare(strict_types=1);

namespace Sabri\CF03\Integration;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Domain\Money;

final class File00FinancialFact
{
    public const CONTRACT_VERSION = '1.0';

    public function __construct(
        private readonly string $eventId,
        private readonly FinancialFactType $type,
        private readonly string $userReference,
        private readonly string $productId,
        private readonly string $priceVersionId,
        private readonly string $financialReference,
        private readonly Money $amount,
        private readonly int $sequence,
        private readonly DateTimeImmutable $occurredAt,
        private readonly string $correlationId
    ) {
        self::assertReference($eventId, 'Event ID');
        self::assertReference($userReference, 'User reference');
        self::assertReference($productId, 'Product ID');
        self::assertReference($priceVersionId, 'Price version ID');
        self::assertReference($financialReference, 'Financial reference');
        self::assertReference($correlationId, 'Correlation ID');

        if ($sequence < 1) {
            throw new InvalidArgumentException('Financial fact sequence must be positive.');
        }
    }

    /** @return array<string,mixed> */
    public function toPayload(): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'event_id' => $this->eventId,
            'event_name' => $this->type->value,
            'user_reference' => $this->userReference,
            'product_id' => $this->productId,
            'price_version_id' => $this->priceVersionId,
            'financial_reference' => $this->financialReference,
            'amount_minor_units' => $this->amount->minorUnits(),
            'currency' => $this->amount->currency(),
            'sequence' => $this->sequence,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'correlation_id' => $this->correlationId,
        ];
    }

    private static function assertReference(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $value) !== 1) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
    }
}
