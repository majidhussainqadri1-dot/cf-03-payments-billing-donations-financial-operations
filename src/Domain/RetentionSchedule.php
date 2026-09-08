<?php

declare(strict_types=1);

namespace Sabri\CF03\Domain;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class RetentionSchedule
{
    /** @var array<string,array{class:string,period:string,delete_mode:string}> */
    private const RULES = [
        'product_price_history' => ['class' => 'C2', 'period' => 'P20Y', 'delete_mode' => 'retain'],
        'payment_intent' => ['class' => 'C4', 'period' => 'P10Y', 'delete_mode' => 'anonymize_after'],
        'provider_event' => ['class' => 'C4', 'period' => 'P7Y', 'delete_mode' => 'minimize_after'],
        'ledger' => ['class' => 'C4', 'period' => 'P10Y', 'delete_mode' => 'retain'],
        'invoice_receipt' => ['class' => 'C4', 'period' => 'P10Y', 'delete_mode' => 'retain'],
        'subscription' => ['class' => 'C4', 'period' => 'P10Y', 'delete_mode' => 'anonymize_after'],
        'refund_chargeback' => ['class' => 'C4', 'period' => 'P10Y', 'delete_mode' => 'retain'],
        'donation' => ['class' => 'C4', 'period' => 'P10Y', 'delete_mode' => 'anonymize_after'],
        'settlement_reconciliation' => ['class' => 'C4', 'period' => 'P10Y', 'delete_mode' => 'retain'],
        'finance_audit' => ['class' => 'C4', 'period' => 'P7Y', 'delete_mode' => 'retain'],
        'guest_prompt_state' => ['class' => 'C3', 'period' => 'P35D', 'delete_mode' => 'delete'],
    ];

    /** @return array{class:string,expires_at:string,delete_mode:string} */
    public function ruleFor(string $category, DateTimeImmutable $createdAt, bool $legalHold = false): array
    {
        if (! isset(self::RULES[$category])) {
            throw new InvalidArgumentException('Unknown financial retention category.');
        }
        $rule = self::RULES[$category];
        $expiresAt = $createdAt->add(new DateInterval($rule['period']));

        return [
            'class' => $rule['class'],
            'expires_at' => $legalHold ? 'legal_hold' : $expiresAt->format(DATE_ATOM),
            'delete_mode' => $legalHold ? 'retain' : $rule['delete_mode'],
        ];
    }

    public function assertDeletionAllowed(string $category, DateTimeImmutable $createdAt, DateTimeImmutable $now, bool $legalHold): void
    {
        $rule = $this->ruleFor($category, $createdAt, $legalHold);
        if ($legalHold) {
            throw new InvariantViolation('Financial record is under legal hold.');
        }
        if ($rule['delete_mode'] === 'retain') {
            throw new InvariantViolation('Financial record requires retained immutable history.');
        }
        if ($now < new DateTimeImmutable($rule['expires_at'])) {
            throw new InvariantViolation('Financial retention period has not expired.');
        }
    }
}
