<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class Invoice
{
    /** @param list<array{description:string,quantity:int,unit_minor:int}> $items */
    public function __construct(
        private readonly string $invoiceId,
        private readonly string $invoiceNumber,
        private readonly string $ownerReference,
        private readonly string $sellerLegalName,
        private readonly array $items,
        private readonly Money $total,
        private readonly string $status,
        private readonly string $snapshotHash
    ) {
        foreach ([$invoiceId, $invoiceNumber, $ownerReference, $sellerLegalName, $status] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Invoice identity fields are required.');
            }
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $snapshotHash)) {
            throw new InvalidArgumentException('Invoice snapshot hash must be SHA-256.');
        }
        $sum = 0;
        foreach ($items as $item) {
            if ($item['quantity'] <= 0 || $item['unit_minor'] < 0 || trim($item['description']) === '') {
                throw new InvalidArgumentException('Invoice item is invalid.');
            }
            $line = $item['quantity'] * $item['unit_minor'];
            if ($line > PHP_INT_MAX - $sum) {
                throw new InvariantViolation('Invoice total overflow.');
            }
            $sum += $line;
        }
        if ($sum !== $total->minorUnits()) {
            throw new InvariantViolation('Invoice items must equal invoice total.');
        }
    }

    public function invoiceId(): string { return $this->invoiceId; }
    public function ownerReference(): string { return $this->ownerReference; }
    public function total(): Money { return $this->total; }
    public function snapshotHash(): string { return $this->snapshotHash; }
}
