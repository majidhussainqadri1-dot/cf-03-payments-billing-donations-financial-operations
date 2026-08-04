<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class Invoice
{
    private const ALLOWED_STATUSES = ['draft', 'issued', 'paid', 'voided', 'refunded'];

    /** @var list<array{description:string,quantity:int,unit_minor:int}> */
    private readonly array $items;

    /** @param list<array{description:string,quantity:int,unit_minor:int}> $items */
    public function __construct(
        private readonly string $invoiceId,
        private readonly string $invoiceNumber,
        private readonly string $ownerReference,
        private readonly string $sellerLegalName,
        array $items,
        private readonly Money $total,
        private readonly string $status,
        private readonly string $snapshotHash
    ) {
        foreach ([$invoiceId, $invoiceNumber, $ownerReference] as $value) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{2,191}$/', $value) !== 1) {
                throw new InvalidArgumentException('Invoice identity reference is invalid.');
            }
        }
        if (trim($sellerLegalName) === '' || strlen($sellerLegalName) > 191) {
            throw new InvalidArgumentException('Invoice seller legal name is invalid.');
        }
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Invoice status is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $snapshotHash) !== 1) {
            throw new InvalidArgumentException('Invoice snapshot hash must be SHA-256.');
        }
        if ($items === [] || count($items) > 1000) {
            throw new InvalidArgumentException('Invoice requires a bounded non-empty item list.');
        }

        $sum = 0;
        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)
                || array_keys($item) !== ['description', 'quantity', 'unit_minor']
                || ! is_string($item['description'])
                || ! is_int($item['quantity'])
                || ! is_int($item['unit_minor'])
                || trim($item['description']) === ''
                || strlen($item['description']) > 255
                || $item['quantity'] <= 0
                || $item['quantity'] > 1000000
                || $item['unit_minor'] < 0
            ) {
                throw new InvalidArgumentException('Invoice item is invalid.');
            }
            if ($item['unit_minor'] !== 0 && $item['quantity'] > intdiv(PHP_INT_MAX, $item['unit_minor'])) {
                throw new InvariantViolation('Invoice line total overflow.');
            }
            $line = $item['quantity'] * $item['unit_minor'];
            if ($line > PHP_INT_MAX - $sum) {
                throw new InvariantViolation('Invoice total overflow.');
            }
            $sum += $line;
            $normalized[] = $item;
        }
        if ($sum !== $total->minorUnits()) {
            throw new InvariantViolation('Invoice items must equal invoice total.');
        }
        $this->items = $normalized;
    }

    public function invoiceId(): string { return $this->invoiceId; }
    public function ownerReference(): string { return $this->ownerReference; }
    public function total(): Money { return $this->total; }
    public function snapshotHash(): string { return $this->snapshotHash; }
    public function status(): string { return $this->status; }

    /** @return list<array{description:string,quantity:int,unit_minor:int}> */
    public function items(): array { return $this->items; }
}
