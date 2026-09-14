<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Contracts\RetentionActionExecutor;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class RetentionOperationsService
{
    private const MODES = ['archive','anonymize','delete','retain_immutable'];

    /**
     * Active v4 retention law. The service accepts only record types with an
     * explicit minimum and action contract; callers cannot shorten retention
     * by supplying an arbitrary expires_at/delete_mode pair.
     *
     * @var array<string,array{class:string,period:string,mode:string}>
     */
    private const POLICY = [
        'product_price_history' => ['class'=>'C2','period'=>'P20Y','mode'=>'retain_immutable'],
        'payment_intent' => ['class'=>'C4','period'=>'P10Y','mode'=>'anonymize'],
        'provider_event' => ['class'=>'C4','period'=>'P7Y','mode'=>'anonymize'],
        'ledger_transaction' => ['class'=>'C4','period'=>'P10Y','mode'=>'retain_immutable'],
        'ledger_entry' => ['class'=>'C4','period'=>'P10Y','mode'=>'retain_immutable'],
        'invoice' => ['class'=>'C4','period'=>'P10Y','mode'=>'retain_immutable'],
        'receipt' => ['class'=>'C4','period'=>'P10Y','mode'=>'retain_immutable'],
        'refund_record' => ['class'=>'C4','period'=>'P10Y','mode'=>'retain_immutable'],
        'chargeback_record' => ['class'=>'C4','period'=>'P10Y','mode'=>'retain_immutable'],
        'donation' => ['class'=>'C4','period'=>'P10Y','mode'=>'anonymize'],
        'settlement_batch' => ['class'=>'C4','period'=>'P10Y','mode'=>'retain_immutable'],
        'reconciliation_exception' => ['class'=>'C4','period'=>'P10Y','mode'=>'retain_immutable'],
        'finance_period' => ['class'=>'C4','period'=>'P10Y','mode'=>'retain_immutable'],
        'audit_event' => ['class'=>'C4','period'=>'P7Y','mode'=>'retain_immutable'],
        'guest_prompt_state' => ['class'=>'C3','period'=>'P35D','mode'=>'delete'],
    ];

    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly RetentionActionExecutor $executor
    ) {}

    /** @return array<string,mixed> */
    public function schedule(
        string $recordType,
        string $recordReference,
        string $dataClass,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $expiresAt,
        string $deleteMode
    ): array {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/', $recordType) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $recordReference) !== 1
            || preg_match('/^[A-Z][0-9A-Z]{0,7}$/', $dataClass) !== 1
            || !in_array($deleteMode, self::MODES, true)
        ) {
            throw new InvalidArgumentException('Retention schedule fields are invalid.');
        }

        $policy = self::POLICY[$recordType] ?? null;
        if ($policy === null) {
            throw new InvariantViolation('Retention record type has no approved active-v4 policy.');
        }
        if ($dataClass !== $policy['class']) {
            throw new InvariantViolation('Retention data class does not match the canonical record policy.');
        }
        if ($deleteMode !== $policy['mode']) {
            throw new InvariantViolation('Retention action mode does not match the canonical record policy.');
        }

        $minimumExpiry = $createdAt->add(new DateInterval($policy['period']));
        if ($expiresAt === null) {
            if ($deleteMode !== 'retain_immutable') {
                throw new InvariantViolation('Actionable retention records require a bounded expiry.');
            }
        } elseif ($expiresAt < $minimumExpiry) {
            throw new InvariantViolation('Retention expiry is earlier than the canonical minimum period.');
        }

        $record = [
            'record_type'=>$recordType,
            'record_ref'=>$recordReference,
            'data_class'=>$dataClass,
            'created_at'=>$createdAt,
            'expires_at'=>$expiresAt,
            'delete_mode'=>$deleteMode,
            'legal_hold'=>false,
            'legal_hold_ref'=>null,
            'actioned_at'=>null,
        ];

        $existing = $this->repository->get('retention_ledger', $recordReference);
        if ($existing !== null) {
            if ((string)($existing['record_type'] ?? '') === $recordType
                && (string)($existing['data_class'] ?? '') === $dataClass
                && self::sameDate($existing['created_at'] ?? null, $createdAt)
                && self::sameNullableDate($existing['expires_at'] ?? null, $expiresAt)
                && (string)($existing['delete_mode'] ?? '') === $deleteMode
            ) {
                return $existing + ['reused'=>true];
            }
            throw new InvariantViolation('Retention record reference already exists with different immutable policy terms.');
        }

        $this->repository->insert('retention_ledger', $recordReference, $record);
        return $record + ['reused'=>false];
    }

    /** @return array<string,mixed> */
    public function placeLegalHold(string $recordReference, string $holdReference): array
    {
        $this->reference($recordReference);
        $this->reference($holdReference);
        $updated = $this->repository->updateWhere(
            'retention_ledger',
            ['record_ref'=>$recordReference,'legal_hold'=>false],
            ['legal_hold'=>true,'legal_hold_ref'=>$holdReference]
        );
        if ($updated !== 1) {
            throw new InvariantViolation('Retention record is missing or already on legal hold.');
        }
        return ['record_ref'=>$recordReference,'legal_hold'=>true,'legal_hold_ref'=>$holdReference];
    }

    /** @return array<string,mixed> */
    public function releaseLegalHold(string $recordReference, string $holdReference): array
    {
        $this->reference($recordReference);
        $this->reference($holdReference);
        $updated = $this->repository->updateWhere(
            'retention_ledger',
            ['record_ref'=>$recordReference,'legal_hold'=>true,'legal_hold_ref'=>$holdReference],
            ['legal_hold'=>false,'legal_hold_ref'=>null]
        );
        if ($updated !== 1) {
            throw new InvariantViolation('Legal hold release reference does not match.');
        }
        return ['record_ref'=>$recordReference,'legal_hold'=>false];
    }

    /** @return array<string,mixed> */
    public function executeDue(string $recordReference, DateTimeImmutable $now): array
    {
        $this->reference($recordReference);
        $record = $this->repository->get('retention_ledger', $recordReference);
        if ($record === null) {
            throw new InvariantViolation('Retention record was not found.');
        }
        $this->assertPersistedPolicy($record);
        if ((bool)$record['legal_hold']) {
            throw new InvariantViolation('Legal hold blocks retention action.');
        }
        if ($record['actioned_at'] !== null) {
            return ['record_ref'=>$recordReference,'status'=>'already_actioned'];
        }
        if ($record['expires_at'] === null) {
            return ['record_ref'=>$recordReference,'status'=>'retained'];
        }
        $expires = self::date($record['expires_at']);
        if ($expires > $now) {
            throw new InvariantViolation('Retention action is not due.');
        }
        $mode = (string)$record['delete_mode'];
        if ($mode === 'retain_immutable') {
            return ['record_ref'=>$recordReference,'status'=>'retained_immutable'];
        }

        $evidence = match ($mode) {
            'archive'=>$this->executor->archive((string)$record['record_type'], $recordReference),
            'anonymize'=>$this->executor->anonymize((string)$record['record_type'], $recordReference),
            'delete'=>$this->executor->delete((string)$record['record_type'], $recordReference),
            default=>throw new InvariantViolation('Unknown retention action mode.'),
        };
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $evidence) !== 1) {
            throw new InvariantViolation('Retention executor returned invalid evidence.');
        }
        $updated = $this->repository->updateWhere(
            'retention_ledger',
            ['record_ref'=>$recordReference,'actioned_at'=>null,'legal_hold'=>false],
            ['actioned_at'=>$now]
        );
        if ($updated !== 1) {
            throw new InvariantViolation('Retention action evidence could not be committed.');
        }
        return [
            'record_ref'=>$recordReference,
            'status'=>$mode,
            'evidence_reference'=>$evidence,
            'actioned_at'=>$now->format(DATE_ATOM),
        ];
    }

    /** @param array<string,mixed> $record */
    private function assertPersistedPolicy(array $record): void
    {
        $recordType = (string)($record['record_type'] ?? '');
        $policy = self::POLICY[$recordType] ?? null;
        if ($policy === null
            || (string)($record['data_class'] ?? '') !== $policy['class']
            || (string)($record['delete_mode'] ?? '') !== $policy['mode']
        ) {
            throw new InvariantViolation('Persisted retention record violates the active-v4 policy.');
        }
        $createdAt = self::date($record['created_at'] ?? null);
        $expiresAt = $record['expires_at'] ?? null;
        if ($expiresAt === null) {
            if ($policy['mode'] !== 'retain_immutable') {
                throw new InvariantViolation('Persisted actionable retention record has no expiry.');
            }
            return;
        }
        $minimum = $createdAt->add(new DateInterval($policy['period']));
        if (self::date($expiresAt) < $minimum) {
            throw new InvariantViolation('Persisted retention expiry violates the canonical minimum period.');
        }
    }

    private function reference(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $value) !== 1) {
            throw new InvalidArgumentException('Retention reference is invalid.');
        }
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            throw new InvariantViolation('Retention timestamp is missing.');
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $error) {
            throw new InvariantViolation('Retention timestamp is invalid.', 0, $error);
        }
    }

    private static function sameDate(mixed $value, DateTimeImmutable $expected): bool
    {
        try {
            return self::date($value)->getTimestamp() === $expected->getTimestamp();
        } catch (Throwable) {
            return false;
        }
    }

    private static function sameNullableDate(mixed $value, ?DateTimeImmutable $expected): bool
    {
        if ($value === null || $expected === null) {
            return $value === null && $expected === null;
        }
        return self::sameDate($value, $expected);
    }
}
