<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\PaidCapabilityAuthorization;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Contracts\UsageSigningSecretResolver;
use Sabri\CF03\Domain\AiUsageAuthorization;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\ProductKind;
use Sabri\CF03\Support\InvariantViolation;

final class AiUsageBillingService
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly PaidCapabilityAuthorization $authorization,
        private readonly UsageSigningSecretResolver $secrets,
        private readonly FinancialAuditService $audit
    ) {}

    /** @return array<string,mixed> */
    public function authorize(
        AiUsageAuthorization $authorization,
        string $decisionReference,
        DateTimeImmutable $createdAt
    ): array {
        $this->authorization->assertAuthorized('ai_usage', $decisionReference);
        $product = $this->repository->get('products', $authorization->productId());
        $price = $this->repository->get('prices', $authorization->priceVersionId());
        if ($product === null || $price === null
            || ($product['kind'] ?? null) !== ProductKind::AI_USAGE->value
            || ($product['lifecycle_state'] ?? null) !== 'active'
            || ($price['approval_state'] ?? null) !== 'approved'
            || ($price['product_id'] ?? null) !== $authorization->productId()
            || ($price['currency'] ?? null) !== $authorization->hardCap()->currency()
        ) {
            throw new InvariantViolation('AI usage authorization requires an approved metered product and price.');
        }
        $record = [
            'authorization_id' => $authorization->authorizationId(),
            'actor_ref' => $authorization->actorReference(),
            'product_id' => $authorization->productId(),
            'price_version_id' => $authorization->priceVersionId(),
            'maximum_units' => $authorization->maximumUnits(),
            'hard_cap_minor' => $authorization->hardCap()->minorUnits(),
            'currency' => $authorization->hardCap()->currency(),
            'valid_until' => $authorization->validUntil(),
            'state' => 'active',
            'policy_version' => $decisionReference,
            'record_version' => 1,
            'created_at' => $createdAt,
        ];
        $existing = $this->repository->get('usage_authorizations', $authorization->authorizationId());
        if ($existing !== null) {
            if (($existing['actor_ref'] ?? null) === $authorization->actorReference()
                && ($existing['product_id'] ?? null) === $authorization->productId()
                && ($existing['price_version_id'] ?? null) === $authorization->priceVersionId()
            ) {
                return self::safeAuthorization($existing) + ['reused' => true];
            }
            throw new InvariantViolation('AI usage authorization identifier already exists with different terms.');
        }
        $this->repository->insert('usage_authorizations', $authorization->authorizationId(), $record);
        return self::safeAuthorization($record) + ['version' => 1, 'reused' => false];
    }

    /** @return array<string,mixed> */
    public function recordUsage(
        string $usageId,
        string $authorizationId,
        string $producerReference,
        string $keyVersion,
        int $units,
        DateTimeImmutable $occurredAt,
        string $signatureHex,
        string $decisionReference
    ): array {
        $this->authorization->assertAuthorized('ai_usage', $decisionReference);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $producerReference) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,63}$/', $keyVersion) !== 1
        ) {
            throw new InvalidArgumentException('AI usage producer or key version is invalid.');
        }
        $record = $this->repository->get('usage_authorizations', $authorizationId);
        if ($record === null || ($record['state'] ?? null) !== 'active'
            || ($record['policy_version'] ?? null) !== $decisionReference
        ) {
            throw new InvariantViolation('AI usage authorization is unavailable or policy-mismatched.');
        }
        $authorization = $this->hydrate($record);
        $secret = $this->secrets->resolve($producerReference, $keyVersion);
        $authorization->assertSignedUsage($usageId, $producerReference, $units, $occurredAt, $signatureHex, $secret);

        $existing = $this->repository->get('usage_facts', $usageId);
        if ($existing !== null) {
            if (($existing['authorization_id'] ?? null) === $authorizationId
                && (int)($existing['units'] ?? -1) === $units
                && ($existing['producer_ref'] ?? null) === $producerReference
                && ($existing['signature_hash'] ?? null) === hash('sha256', $signatureHex)
            ) {
                return self::safeUsage($existing) + ['reused' => true];
            }
            throw new InvariantViolation('AI usage identifier was replayed with different evidence.');
        }

        $usedUnits = 0;
        $usedAmount = 0;
        foreach ($this->repository->find('usage_facts', ['authorization_id' => $authorizationId], 500) as $fact) {
            if (($fact['state'] ?? null) === 'accepted') {
                $usedUnits += (int)$fact['units'];
                $usedAmount += (int)$fact['amount_minor'];
            }
        }
        if ($usedUnits + $units > $authorization->maximumUnits()) {
            throw new InvariantViolation('AI usage exceeds the authorized unit limit.');
        }

        $price = $this->repository->get('prices', $authorization->priceVersionId());
        if ($price === null || ($price['approval_state'] ?? null) !== 'approved') {
            throw new InvariantViolation('AI usage price snapshot is unavailable.');
        }
        $unitMinor = (int)$price['amount_minor'];
        if ($unitMinor < 0 || ($price['currency'] ?? null) !== $authorization->hardCap()->currency()
            || ($units > 0 && $unitMinor > intdiv(PHP_INT_MAX, $units))
        ) {
            throw new InvariantViolation('AI usage price cannot be multiplied safely.');
        }
        $amountMinor = $unitMinor * $units;
        if ($usedAmount + $amountMinor > $authorization->hardCap()->minorUnits()) {
            throw new InvariantViolation('AI usage exceeds the authorized monetary hard cap.');
        }

        $transactionId = 'txn.usage.'.substr(hash('sha256', $usageId), 0, 32);
        $traceId = 'trace:usage:'.substr(hash('sha256', $authorizationId), 0, 24);
        $this->repository->transaction(function () use (
            $usageId,
            $authorization,
            $producerReference,
            $units,
            $occurredAt,
            $signatureHex,
            $amountMinor,
            $transactionId,
            $traceId
        ): void {
            $this->repository->insert('usage_facts', $usageId, [
                'usage_id' => $usageId,
                'authorization_id' => $authorization->authorizationId(),
                'actor_ref' => $authorization->actorReference(),
                'product_id' => $authorization->productId(),
                'units' => $units,
                'occurred_at' => $occurredAt,
                'producer_ref' => $producerReference,
                'signature_hash' => hash('sha256', $signatureHex),
                'state' => 'accepted',
                'amount_minor' => $amountMinor,
                'currency' => $authorization->hardCap()->currency(),
                'record_version' => 1,
            ]);
            $this->repository->insert('ledger_transactions', $transactionId, [
                'transaction_id' => $transactionId,
                'source_type' => 'ai_usage_fact',
                'source_ref' => $usageId,
                'effective_at' => $occurredAt,
                'recorded_at' => $occurredAt,
                'actor_ref' => 'system:ai-usage',
                'reason' => 'signed_ai_usage_charge',
                'period_id' => $occurredAt->format('Y-m'),
                'reversal_of' => null,
                'trace_id' => $traceId,
            ]);
            $this->entry($transactionId, $usageId.':receivable', 'asset.accounts_receivable', 'debit', $amountMinor, $authorization->hardCap()->currency());
            $this->entry($transactionId, $usageId.':income', 'income.ai_usage', 'credit', $amountMinor, $authorization->hardCap()->currency());
            $this->event($usageId, $authorization, $units, $amountMinor, $traceId, $occurredAt);
        });

        $this->audit->append(new AuditEnvelope(
            'audit:usage:'.substr(hash('sha256', $usageId), 0, 32),
            $producerReference,
            'ai_usage_recorded',
            'ai_usage_fact',
            $usageId,
            'signed_metered_usage',
            AuditOutcome::SUCCEEDED,
            $occurredAt,
            $traceId,
            [
                'authorization_id' => $authorizationId,
                'units' => $units,
                'amount_minor' => $amountMinor,
                'currency' => $authorization->hardCap()->currency(),
                'signature_sha256' => hash('sha256', $signatureHex),
            ]
        ));

        $stored = $this->repository->get('usage_facts', $usageId);
        if ($stored === null) {
            throw new InvariantViolation('Accepted AI usage fact disappeared.');
        }
        return self::safeUsage($stored) + ['transaction_id' => $transactionId, 'reused' => false];
    }

    /** @param array<string,mixed> $record */
    private function hydrate(array $record): AiUsageAuthorization
    {
        return new AiUsageAuthorization(
            (string)$record['authorization_id'],
            (string)$record['actor_ref'],
            (string)$record['product_id'],
            (string)$record['price_version_id'],
            (int)$record['maximum_units'],
            new Money((int)$record['hard_cap_minor'], (string)$record['currency']),
            self::date($record['valid_until'])
        );
    }

    private function entry(
        string $transactionId,
        string $sourceReference,
        string $account,
        string $direction,
        int $amountMinor,
        string $currency
    ): void {
        $this->repository->insert('ledger_entries', $sourceReference, [
            'transaction_id' => $transactionId,
            'account' => $account,
            'direction' => $direction,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'source_ref' => $sourceReference,
        ]);
    }

    private function event(
        string $usageId,
        AiUsageAuthorization $authorization,
        int $units,
        int $amountMinor,
        string $traceId,
        DateTimeImmutable $at
    ): void {
        $payload = [
            'usage_id' => $usageId,
            'authorization_id' => $authorization->authorizationId(),
            'actor_ref' => $authorization->actorReference(),
            'product_id' => $authorization->productId(),
            'units' => $units,
            'amount_minor' => $amountMinor,
            'currency' => $authorization->hardCap()->currency(),
            'occurred_at' => $at->format(DATE_ATOM),
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new InvariantViolation('AI usage event could not be encoded.');
        }
        $eventId = 'event.usage.'.substr(hash('sha256', $encoded), 0, 32);
        $this->repository->insert('outbox', $eventId, [
            'event_id' => $eventId,
            'event_type' => 'AiUsageRecorded',
            'aggregate_id' => $authorization->authorizationId(),
            'aggregate_version' => $usageId,
            'schema_version' => '1.0',
            'trace_id' => $traceId,
            'payload_json' => $payload,
            'payload_hash' => hash('sha256', $encoded),
            'state' => 'pending',
            'attempts' => 0,
            'available_at' => $at,
            'leased_until' => null,
            'last_error_code' => null,
            'created_at' => $at,
            'delivered_at' => null,
        ]);
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            throw new InvariantViolation('AI usage authorization date is missing.');
        }
        return new DateTimeImmutable($value);
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private static function safeAuthorization(array $record): array
    {
        return array_intersect_key($record, array_flip([
            'authorization_id','actor_ref','product_id','price_version_id','maximum_units',
            'hard_cap_minor','currency','valid_until','state','policy_version','record_version','version','created_at',
        ]));
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private static function safeUsage(array $record): array
    {
        return array_intersect_key($record, array_flip([
            'usage_id','authorization_id','actor_ref','product_id','units','occurred_at',
            'producer_ref','signature_hash','state','amount_minor','currency','record_version','version',
        ]));
    }
}
