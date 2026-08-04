<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use InvalidArgumentException;

final class DonationPromptContext
{
    /** @var list<string> */
    private const SENSITIVE_CONTEXTS = [
        'login',
        'registration',
        'password_recovery',
        'guardian_consent',
        'clinical_consultation',
        'emergency_warning',
        'support_appeal',
        'payment_error',
    ];

    public function __construct(
        private readonly string $pageViewKey,
        private readonly string $pageContext,
        private readonly int $secondsOnPage,
        private readonly bool $meaningfulInteraction,
        private readonly bool $promptAlreadyShownThisPage,
        private readonly bool $checkoutFailedThisSession
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $pageViewKey) !== 1) {
            throw new InvalidArgumentException('Donation prompt page-view key is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9_.:-]{2,63}$/', $pageContext) !== 1) {
            throw new InvalidArgumentException('Donation prompt page context is invalid.');
        }
        if ($secondsOnPage < 0 || $secondsOnPage > 86400) {
            throw new InvalidArgumentException('Donation prompt engagement time is invalid.');
        }
    }

    public function pageViewKey(): string { return $this->pageViewKey; }
    public function pageContext(): string { return $this->pageContext; }
    public function promptAlreadyShownThisPage(): bool { return $this->promptAlreadyShownThisPage; }
    public function checkoutFailedThisSession(): bool { return $this->checkoutFailedThisSession; }

    public function isSensitiveContext(): bool
    {
        return in_array($this->pageContext, self::SENSITIVE_CONTEXTS, true);
    }

    public function isMeaningfullyEngaged(): bool
    {
        return $this->secondsOnPage >= 30 || $this->meaningfulInteraction;
    }
}
