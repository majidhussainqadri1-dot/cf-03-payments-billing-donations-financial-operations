<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class DonationExpense
{
    /** @var list<string> */
    private const RECEIPT_STATUSES=['pending','received','not_applicable'];

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
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/',$expenseId)!==1) { throw new InvalidArgumentException('Donation expense ID is invalid.'); }
        if ($amount->minorUnits()<=0) { throw new InvalidArgumentException('Donation expense amount must be positive.'); }
        DonationExpenseCategory::assertAllowed($category);
        if (trim($purpose)==='' || strlen($purpose)>500) { throw new InvalidArgumentException('Donation expense purpose is required and bounded.'); }
        foreach([$payeeReference,$approvalReference,$publicDisclosureCategory] as $value){if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,190}$/',$value)!==1){throw new InvalidArgumentException('Donation expense reference is invalid.');}}
        if(!in_array($receiptStatus,self::RECEIPT_STATUSES,true)){throw new InvalidArgumentException('Donation expense receipt status is invalid.');}
        if($founderRelated!==DonationExpenseCategory::isFounderRelated($category)){throw new InvalidArgumentException('Founder-related indicator must match the approved expense category.');}
    }

    public function expenseId(): string{return $this->expenseId;}
    public function amount(): Money{return $this->amount;}
    public function category(): string{return $this->category;}
    public function founderRelated(): bool{return $this->founderRelated;}

    /** @return array<string,mixed> */
    public function toPublicProjection(): array
    {
        return ['expense_id'=>$this->expenseId,'date'=>$this->occurredAt->format('Y-m-d'),'amount_minor'=>$this->amount->minorUnits(),'currency'=>$this->amount->currency(),'category'=>$this->category,'purpose'=>$this->purpose,'receipt_status'=>$this->receiptStatus,'founder_related'=>$this->founderRelated,'public_disclosure_category'=>$this->publicDisclosureCategory];
    }
}
