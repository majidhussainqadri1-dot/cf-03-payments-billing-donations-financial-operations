<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateTimeImmutable;
use Sabri\CF03\Support\InvariantViolation;

final class TrustedDonationFact
{
    private readonly string $providerEventId;
    private readonly DateTimeImmutable $occurredAt;

    public function __construct(
        private readonly DonationFinancialFactType $type,
        ProviderEvidence $evidence,
        private readonly string $providerCode,
        private readonly string $paymentIntentId,
        private readonly Money $amount,
        int $replayWindowSeconds = 300
    ) {
        $evidence->assertTrusted($replayWindowSeconds);
        $evidence->assertMatches($providerCode, $paymentIntentId, $amount);

        if ($evidence->eventType() !== $type->value) {
            throw new InvariantViolation('Trusted donation evidence type does not match the requested donation fact.');
        }

        $this->providerEventId = $evidence->providerEventId();
        $this->occurredAt = $evidence->occurredAt();
    }

    public function assertMatches(string $providerCode, string $paymentIntentId, Money $amount): void
    {
        if ($this->providerCode !== $providerCode
            || $this->paymentIntentId !== $paymentIntentId
            || ! $this->amount->equals($amount)
        ) {
            throw new InvariantViolation('Trusted donation fact is bound to a different provider, intent or amount.');
        }
    }

    public function type(): DonationFinancialFactType
    {
        return $this->type;
    }

    public function providerCode(): string { return $this->providerCode; }
    public function paymentIntentId(): string { return $this->paymentIntentId; }
    public function amount(): Money { return $this->amount; }
    public function providerEventId(): string { return $this->providerEventId; }
    public function occurredAt(): DateTimeImmutable { return $this->occurredAt; }

    public function promptActionOrNull(): ?DonationPromptAction
    {
        return match ($this->type) {
            DonationFinancialFactType::ONE_TIME_COMPLETED => DonationPromptAction::DONATION_COMPLETED_ONE_TIME,
            DonationFinancialFactType::MONTHLY_STARTED => DonationPromptAction::DONATION_COMPLETED_MONTHLY,
            DonationFinancialFactType::MONTHLY_CANCELLED => DonationPromptAction::MONTHLY_CANCELLED,
            DonationFinancialFactType::REFUNDED,
            DonationFinancialFactType::CHARGEDBACK => null,
        };
    }

    public function toPromptAction(): DonationPromptAction
    {
        return $this->promptActionOrNull()
            ?? throw new InvariantViolation('This trusted donation fact does not mutate donation-prompt frequency state.');
    }
}
