<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class FinancialTransparencySnapshot
{
    /** @var array<string,Money> */
    private array $expenses;

    /** @param array<string,Money> $expenses */
    public function __construct(
        private readonly string $snapshotId,
        private readonly DateTimeImmutable $asOf,
        private readonly Money $totalDonations,
        private readonly Money $currentMonthDonations,
        private readonly Money $currentYearDonations,
        array $expenses,
        private readonly DateTimeImmutable $lastFinancialUpdateAt,
        private readonly string $sourceHash
    ) {
        if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/',$snapshotId)!==1){throw new InvalidArgumentException('Transparency snapshot ID is invalid.');}
        if(preg_match('/^[a-f0-9]{64}$/',$sourceHash)!==1){throw new InvalidArgumentException('Transparency snapshot source hash is invalid.');}
        if($lastFinancialUpdateAt>$asOf){throw new InvalidArgumentException('Financial update time cannot follow the snapshot time.');}
        if($currentMonthDonations->currency()!==$totalDonations->currency()||$currentYearDonations->currency()!==$totalDonations->currency()){throw new InvalidArgumentException('Transparency donation totals must use one currency.');}
        if($currentMonthDonations->minorUnits()>$currentYearDonations->minorUnits()||$currentYearDonations->minorUnits()>$totalDonations->minorUnits()){throw new InvalidArgumentException('Monthly, yearly and lifetime donation totals are inconsistent.');}
        $normalized=[];
        foreach(DonationExpenseCategory::allowed() as $category){$money=$expenses[$category]??Money::zero($totalDonations->currency());if(!$money instanceof Money||$money->currency()!==$totalDonations->currency()){throw new InvalidArgumentException('Transparency expense categories must use the snapshot currency.');}$normalized[$category]=$money;}
        foreach(array_keys($expenses) as $category){DonationExpenseCategory::assertAllowed((string)$category);}
        $this->expenses=$normalized;
        if($this->totalExpenses()->minorUnits()>$totalDonations->minorUnits()){throw new InvariantViolation('Published transparency expenses cannot exceed published received donations.');}
    }

    public function totalExpenses(): Money{$sum=Money::zero($this->totalDonations->currency());foreach($this->expenses as $amount){$sum=$sum->add($amount);}return $sum;}
    public function currentBalance(): Money{return $this->totalDonations->subtract($this->totalExpenses());}
    public function founderPaidOrWithdrawn(): Money{$sum=Money::zero($this->totalDonations->currency());foreach(DonationExpenseCategory::founderRelated() as $category){$sum=$sum->add($this->expenses[$category]);}return $sum;}

    /** @return array<string,mixed> */
    public function toPublicProjection(): array
    {
        $categories=[];foreach($this->expenses as $category=>$amount){$categories[$category]=$amount->minorUnits();}
        return ['snapshot_id'=>$this->snapshotId,'as_of'=>$this->asOf->format(DATE_ATOM),'currency'=>$this->totalDonations->currency(),'total_donations_minor'=>$this->totalDonations->minorUnits(),'current_month_donations_minor'=>$this->currentMonthDonations->minorUnits(),'current_year_donations_minor'=>$this->currentYearDonations->minorUnits(),'total_expenses_minor'=>$this->totalExpenses()->minorUnits(),'expense_categories_minor'=>$categories,'founder_paid_or_withdrawn_minor'=>$this->founderPaidOrWithdrawn()->minorUnits(),'current_balance_minor'=>$this->currentBalance()->minorUnits(),'last_financial_update_at'=>$this->lastFinancialUpdateAt->format(DATE_ATOM),'founder_owned'=>true,'is_trust'=>false,'donor_identity_public'=>false,'bank_or_payment_credentials_public'=>false];
    }

    /** @param array<string,mixed> $record */
    public static function fromArray(array $record): self
    {
        $currency=(string)($record['currency']??'');$expenses=[];
        foreach(($record['expense_categories_minor']??[]) as $category=>$amount){if(!is_int($amount)){throw new InvalidArgumentException('Transparency expense amount must be an integer.');}$expenses[(string)$category]=new Money($amount,$currency);}
        return new self((string)($record['snapshot_id']??''),new DateTimeImmutable((string)($record['as_of']??'')),new Money(self::integer($record,'total_donations_minor'),$currency),new Money(self::integer($record,'current_month_donations_minor'),$currency),new Money(self::integer($record,'current_year_donations_minor'),$currency),$expenses,new DateTimeImmutable((string)($record['last_financial_update_at']??'')),(string)($record['source_hash']??''));
    }

    /** @param array<string,mixed> $record */
    private static function integer(array $record,string $key): int{$value=$record[$key]??null;if(!is_int($value)){throw new InvalidArgumentException('Transparency amount is missing or non-integer: '.$key.'.');}return $value;}
}
