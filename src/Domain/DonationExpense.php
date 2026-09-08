<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class DonationExpense
{
    public function __construct(
        private readonly string $expenseId,
        private readonly DateTimeImmutable $occurredAt,
        private readonly Money $amount,
        private readonly string $category,
        private readonly string $purpose,
        private readonly string $payeeReference,
        private readonly string $approvalReference,
        private readonly string $receiptStatus,
        private readonly bool $founderRelated,
        private readonly string $publicDisclosureCategory
    ) {
        foreach ([$expenseId, $payeeReference, $approvalReference, $publicDisclosureCategory] as $reference) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $reference) !== 1) {
                throw new InvalidArgumentException('Donation expense reference is invalid.');
            }
        }
        DonationExpenseCategory::assertAllowed($category);
        if ($amount->minorUnits() <= 0 || trim($purpose) === '' || strlen($purpose) > 500) {
            throw new InvalidArgumentException('Donation expense amount or purpose is invalid.');
        }
        if (!in_array($receiptStatus, ['not_required', 'requested', 'received', 'verified', 'missing'], true)) {
            throw new InvalidArgumentException('Donation expense receipt status is invalid.');
        }
        if ($founderRelated !== DonationExpenseCategory::isFounderRelated($category)) {
            throw new InvalidArgumentException('Founder-related expense indicator does not match the approved category.');
        }
    }

    public function expenseId(): string { return $this->expenseId; }
    public function occurredAt(): DateTimeImmutable { return $this->occurredAt; }
    public function amount(): Money { return $this->amount; }
    public function category(): string { return $this->category; }
    public function purpose(): string { return $this->purpose; }
    public function payeeReference(): string { return $this->payeeReference; }
    public function approvalReference(): string { return $this->approvalReference; }
    public function receiptStatus(): string { return $this->receiptStatus; }
    public function founderRelated(): bool { return $this->founderRelated; }
    public function publicDisclosureCategory(): string { return $this->publicDisclosureCategory; }

    /** @return array<string,mixed> */
    public function toPublicProjection(): array
    {
        return [
            'expense_id' => $this->expenseId,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'amount_minor' => $this->amount->minorUnits(),
            'currency' => $this->amount->currency(),
            'category' => $this->category,
            'public_disclosure_category' => $this->publicDisclosureCategory,
            'founder_related' => $this->founderRelated,
            'purpose' => $this->purpose,
            'receipt_status' => $this->receiptStatus,
        ];
    }
}
