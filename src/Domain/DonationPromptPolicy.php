<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;

final class DonationPromptPolicy
{
    public const MONTHLY_MINIMUM_INTERVAL='P30D';
    public const COMPLETED_DONATION_SUPPRESSION='P30D';
    public const MINIMUM_ENGAGEMENT_SECONDS=30;

    public function decide(DonationPromptState $state,DonationPromptContext $context,DateTimeImmutable $now): DonationPromptDecision
    {
        if($state->frequencyPreference()===DonationFrequencyPreference::MONTHLY_ACTIVE){return DonationPromptDecision::suppress('active_monthly_donor');}
        if($context->isSensitiveContext()){return DonationPromptDecision::suppress('sensitive_context');}
        if($context->promptAlreadyShownThisPage()){return DonationPromptDecision::suppress('page_view_cap');}
        if($context->promptAlreadyShownThisSession()){return DonationPromptDecision::suppress('session_cap');}
        if($context->checkoutFailedThisSession()){return DonationPromptDecision::suppress('checkout_failed_this_session');}
        if(!$context->isMeaningfullyEngaged()){return DonationPromptDecision::suppress('engagement_threshold_not_met');}
        $nextEligibleAt=$state->nextDonationPromptAt();
        if($state->lastDonationPromptAt()!==null){$calendarCap=$state->lastDonationPromptAt()->modify('first day of next month')->setTime(0,0,0);$nextEligibleAt=self::later($nextEligibleAt,$calendarCap);}
        $nextEligibleAt=self::later($nextEligibleAt,$state->snoozedUntil());
        if($state->lastDonationCompletedAt()!==null){$nextEligibleAt=self::later($nextEligibleAt,$state->lastDonationCompletedAt()->modify('+30 days'));}
        if($nextEligibleAt!==null&&$now<$nextEligibleAt){return DonationPromptDecision::suppress('monthly_frequency_cap',$nextEligibleAt);}
        return DonationPromptDecision::showNow();
    }
    private static function later(?DateTimeImmutable $left,?DateTimeImmutable $right): ?DateTimeImmutable{if($left===null){return $right;}if($right===null){return $left;}return $left>=$right?$left:$right;}
}
