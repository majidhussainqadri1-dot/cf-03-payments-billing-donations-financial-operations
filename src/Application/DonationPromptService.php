<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\DonationPromptStateStore;
use Sabri\CF03\Domain\DonationPromptAction;
use Sabri\CF03\Domain\DonationPromptContext;
use Sabri\CF03\Domain\DonationPromptDecision;
use Sabri\CF03\Domain\DonationPromptPolicy;
use Sabri\CF03\Domain\TrustedDonationFact;
use Sabri\CF03\Support\InvariantViolation;

final class DonationPromptService
{
    /** @var list<DonationPromptAction> */
    private const USER_ACTIONS = [
        DonationPromptAction::SHOWN,
        DonationPromptAction::REMIND_LATER,
        DonationPromptAction::NOT_NOW,
        DonationPromptAction::CLOSE,
    ];

    /** @param null|callable():DateTimeImmutable $clock */
    public function __construct(
        private readonly DonationPromptStateStore $store,
        private readonly DonationPromptPolicy $policy = new DonationPromptPolicy(),
        private readonly mixed $clock = null
    ) {
        if ($clock !== null && !is_callable($clock)) {
            throw new InvalidArgumentException('Donation prompt clock must be callable when provided.');
        }
    }

    public function decision(string $subjectReference, DonationPromptContext $context): DonationPromptDecision
    {
        self::assertSubject($subjectReference);
        return $this->policy->decide($this->store->load($subjectReference), $context, $this->now());
    }

    public function recordUserAction(string $subjectReference, DonationPromptAction $action): void
    {
        self::assertSubject($subjectReference);
        if (!in_array($action, self::USER_ACTIONS, true)) {
            throw new InvariantViolation('Donation completion state requires a trusted financial fact.');
        }
        $this->apply($subjectReference, $action, $this->now());
    }

    public function recordTrustedFinancialFact(string $subjectReference, TrustedDonationFact $fact): void
    {
        self::assertSubject($subjectReference);
        if ($fact->subjectReference() === 'subject:unbound'
            || !hash_equals($subjectReference, $fact->subjectReference())
        ) {
            throw new InvariantViolation('Trusted donation fact subject does not match the prompt-state subject.');
        }
        $this->apply($subjectReference, $fact->toPromptAction(), $fact->occurredAt());
    }

    private function apply(string $subjectReference, DonationPromptAction $action, DateTimeImmutable $at): void
    {
        $current = $this->store->load($subjectReference);
        $this->store->save($subjectReference, $current->apply($action, $at));
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->clock === null ? new DateTimeImmutable('now') : ($this->clock)();
        if (!$now instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('Donation prompt clock must return DateTimeImmutable.');
        }
        return $now;
    }

    private static function assertSubject(string $subjectReference): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $subjectReference) !== 1) {
            throw new InvalidArgumentException('Donation prompt subject reference is invalid.');
        }
    }
}
