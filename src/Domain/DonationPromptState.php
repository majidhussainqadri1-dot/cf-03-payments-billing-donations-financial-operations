<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;

final class DonationPromptState
{
    public function __construct(
        private readonly ?DateTimeImmutable $lastDonationPromptAt=null,
        private readonly DonationPromptStatus $status=DonationPromptStatus::NEVER_SEEN,
        private readonly ?DateTimeImmutable $snoozedUntil=null,
        private readonly ?DateTimeImmutable $lastDonationCompletedAt=null,
        private readonly DonationFrequencyPreference $frequencyPreference=DonationFrequencyPreference::NONE,
        private readonly ?DateTimeImmutable $nextDonationPromptAt=null
    ) {}
    public function lastDonationPromptAt(): ?DateTimeImmutable{return $this->lastDonationPromptAt;}
    public function status(): DonationPromptStatus{return $this->status;}
    public function snoozedUntil(): ?DateTimeImmutable{return $this->snoozedUntil;}
    public function lastDonationCompletedAt(): ?DateTimeImmutable{return $this->lastDonationCompletedAt;}
    public function frequencyPreference(): DonationFrequencyPreference{return $this->frequencyPreference;}
    public function nextDonationPromptAt(): ?DateTimeImmutable{return $this->nextDonationPromptAt;}

    public function apply(DonationPromptAction $action,DateTimeImmutable $at): self
    {
        $thirtyDays=$at->modify('+30 days');
        return match($action){
            DonationPromptAction::SHOWN=>new self($at,DonationPromptStatus::SHOWN,$this->snoozedUntil,$this->lastDonationCompletedAt,$this->frequencyPreference,self::firstDayOfNextMonth($at)),
            DonationPromptAction::REMIND_LATER=>new self($this->lastDonationPromptAt??$at,DonationPromptStatus::SNOOZED,$thirtyDays,$this->lastDonationCompletedAt,$this->frequencyPreference,$thirtyDays),
            DonationPromptAction::NOT_NOW=>new self($this->lastDonationPromptAt??$at,DonationPromptStatus::NOT_NOW,$thirtyDays,$this->lastDonationCompletedAt,$this->frequencyPreference,$thirtyDays),
            DonationPromptAction::CLOSE=>new self($this->lastDonationPromptAt??$at,DonationPromptStatus::CLOSED,$thirtyDays,$this->lastDonationCompletedAt,$this->frequencyPreference,$thirtyDays),
            DonationPromptAction::DONATION_COMPLETED_ONE_TIME=>new self($this->lastDonationPromptAt,DonationPromptStatus::DONATION_COMPLETED,$thirtyDays,$at,DonationFrequencyPreference::ONE_TIME,$thirtyDays),
            DonationPromptAction::DONATION_COMPLETED_MONTHLY=>new self($this->lastDonationPromptAt,DonationPromptStatus::MONTHLY_ACTIVE,null,$at,DonationFrequencyPreference::MONTHLY_ACTIVE,null),
            DonationPromptAction::MONTHLY_CANCELLED=>new self($this->lastDonationPromptAt,DonationPromptStatus::DONATION_COMPLETED,$thirtyDays,$this->lastDonationCompletedAt??$at,DonationFrequencyPreference::MONTHLY_CANCELLED,$thirtyDays),
        };
    }

    /** @return array<string,string|null> */
    public function toStorage(): array
    {
        return ['last_donation_prompt_at'=>$this->lastDonationPromptAt?->format(DATE_ATOM),'next_donation_prompt_at'=>$this->nextDonationPromptAt?->format(DATE_ATOM),'donation_prompt_status'=>$this->status->value,'donation_prompt_snoozed_until'=>$this->snoozedUntil?->format(DATE_ATOM),'last_donation_completed_at'=>$this->lastDonationCompletedAt?->format(DATE_ATOM),'recurring_donation_status'=>$this->frequencyPreference->value,'donation_frequency_preference'=>$this->frequencyPreference->value];
    }

    /** @param array<string,mixed> $data */
    public static function fromStorage(array $data): self
    {
        $preference=(string)($data['recurring_donation_status']??$data['donation_frequency_preference']??'');
        return new self(self::date($data['last_donation_prompt_at']??null),DonationPromptStatus::tryFrom((string)($data['donation_prompt_status']??''))??DonationPromptStatus::NEVER_SEEN,self::date($data['donation_prompt_snoozed_until']??null),self::date($data['last_donation_completed_at']??null),DonationFrequencyPreference::tryFrom($preference)??DonationFrequencyPreference::NONE,self::date($data['next_donation_prompt_at']??null));
    }
    private static function firstDayOfNextMonth(DateTimeImmutable $at): DateTimeImmutable{return $at->modify('first day of next month')->setTime(0,0,0);}
    private static function date(mixed $value): ?DateTimeImmutable{if(!is_string($value)||$value===''){return null;}try{return new DateTimeImmutable($value);}catch(\Throwable){return null;}}
}
