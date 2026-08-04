<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use Sabri\CF03\Support\InvariantViolation;

final class TrustedDonationFact
{
    public function __construct(
        private readonly DonationFinancialFactType $type,
        ProviderEvidence $evidence,
        string $expectedProvider,
        string $expectedIntent,
        Money $expectedAmount,
        int $replayWindowSeconds = 300
    ) {
        $evidence->assertTrusted($replayWindowSeconds);
        $evidence->assertMatches($expectedProvider, $expectedIntent, $expectedAmount);

        if ($evidence->eventType() !== $type->value) {
            throw new InvariantViolation('Trusted donation evidence type does not match the requested donation fact.');
        }
    }

    public function type(): DonationFinancialFactType
    {
        return $this->type;
    }

    public function toPromptAction(): DonationPromptAction
    {
        return match ($this->type) {
            DonationFinancialFactType::ONE_TIME_COMPLETED => DonationPromptAction::DONATION_COMPLETED_ONE_TIME,
            DonationFinancialFactType::MONTHLY_STARTED => DonationPromptAction::DONATION_COMPLETED_MONTHLY,
            DonationFinancialFactType::MONTHLY_CANCELLED => DonationPromptAction::MONTHLY_CANCELLED,
        };
    }
}
