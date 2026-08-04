<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;

final class DonationPromptPolicy
{
    public const WEEKLY_INTERVAL = 'P7D';
    public const COMPLETED_DONATION_SUPPRESSION = 'P30D';
    public const MINIMUM_ENGAGEMENT_SECONDS = 30;

    public function decide(
        DonationPromptState $state,
        DonationPromptContext $context,
        DateTimeImmutable $now
    ): DonationPromptDecision {
        if ($state->frequencyPreference() === DonationFrequencyPreference::MONTHLY_ACTIVE) {
            return DonationPromptDecision::suppress('active_monthly_donor');
        }

        if ($context->isSensitiveContext()) {
            return DonationPromptDecision::suppress('sensitive_context');
        }

        if ($context->promptAlreadyShownThisPage()) {
            return DonationPromptDecision::suppress('page_view_cap');
        }

        if ($context->checkoutFailedThisSession()) {
            return DonationPromptDecision::suppress('checkout_failed_this_session');
        }

        if (! $context->isMeaningfullyEngaged()) {
            return DonationPromptDecision::suppress('engagement_threshold_not_met');
        }

        $nextEligibleAt = null;
        if ($state->lastDonationPromptAt() !== null) {
            $nextEligibleAt = $state->lastDonationPromptAt()->modify('+7 days');
        }

        if ($state->snoozedUntil() !== null
            && ($nextEligibleAt === null || $state->snoozedUntil() > $nextEligibleAt)
        ) {
            $nextEligibleAt = $state->snoozedUntil();
        }

        if ($state->lastDonationCompletedAt() !== null) {
            $completedSuppression = $state->lastDonationCompletedAt()->modify('+30 days');
            if ($nextEligibleAt === null || $completedSuppression > $nextEligibleAt) {
                $nextEligibleAt = $completedSuppression;
            }
        }

        if ($nextEligibleAt !== null && $now < $nextEligibleAt) {
            return DonationPromptDecision::suppress('frequency_cap', $nextEligibleAt);
        }

        return DonationPromptDecision::showNow();
    }
}
