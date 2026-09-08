<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Domain\DonationExpense;
use Sabri\CF03\Domain\DonationExpenseCategory;
use Sabri\CF03\Domain\FinancialTransparencySnapshot;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Support\InvariantViolation;

final class ExpenseTransparencyService
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly FinancialAuditService $audit
    ) {}

    /** @return array<string,mixed> */
    public function recordExpense(
        DonationExpense $expense,
        string $recordedBy,
        DateTimeImmutable $recordedAt,
        ?string $sourceTransactionId = null
    ): array {
        self::assertReference($recordedBy, 'Expense recorder');
        if ($sourceTransactionId !== null) {
            self::assertReference($sourceTransactionId, 'Expense source transaction');
        }
        $record = [
            'expense_id' => $expense->expenseId(),
            'occurred_at' => $expense->occurredAt(),
            'amount_minor' => $expense->amount()->minorUnits(),
            'currency' => $expense->amount()->currency(),
            'category' => $expense->category(),
            'purpose' => $expense->purpose(),
            'payee_ref' => $expense->payeeReference(),
            'approval_ref' => $expense->approvalReference(),
            'receipt_status' => $expense->receiptStatus(),
            'founder_related' => $expense->founderRelated(),
            'public_disclosure_category' => $expense->publicDisclosureCategory(),
            'source_transaction_id' => $sourceTransactionId,
            'record_version' => 1,
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ];
        $existing = $this->repository->get('expenses', $expense->expenseId());
        if ($existing !== null) {
            self::assertExpenseParity($existing, $record);
            return $existing + ['reused' => true];
        }

        $this->repository->transaction(function () use ($record, $expense, $recordedBy, $recordedAt): void {
            $this->repository->insert('expenses', $expense->expenseId(), $record);
            $this->audit->append(new AuditEnvelope(
                'audit:expense:'.substr(hash('sha256', $expense->expenseId().'|'.$recordedAt->format(DATE_ATOM)), 0, 32),
                $recordedBy,
                'expense_recorded',
                'donation_expense',
                $expense->expenseId(),
                'financial_transparency',
                AuditOutcome::SUCCEEDED,
                $recordedAt,
                'trace:expense:'.substr(hash('sha256', $expense->expenseId()), 0, 24),
                ['category' => $expense->category(), 'founder_related' => $expense->founderRelated()]
            ));
        });
        return $record + ['reused' => false];
    }

    /** @return array<string,mixed> */
    public function buildAndPublishSnapshot(
        string $periodKey,
        string $currency,
        string $publisherReference,
        DateTimeImmutable $asOf
    ): array {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $periodKey) !== 1) {
            throw new InvalidArgumentException('Transparency period key is invalid.');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Transparency currency is invalid.');
        }
        self::assertReference($publisherReference, 'Transparency publisher');

        $entries = $this->repository->all('ledger_entries');
        $transactions = [];
        foreach ($this->repository->all('ledger_transactions') as $transaction) {
            $transactions[(string)$transaction['transaction_id']] = $transaction;
        }
        $lifetime = 0;
        $month = 0;
        $year = 0;
        $lastUpdate = new DateTimeImmutable('1970-01-01T00:00:00+00:00');
        $yearKey = substr($periodKey, 0, 4);
        foreach ($entries as $entry) {
            if (($entry['currency'] ?? null) !== $currency) {
                continue;
            }
            $account = (string)($entry['account'] ?? '');
            $direction = (string)($entry['direction'] ?? '');
            $signed = 0;
            if ($account === 'income.donation' && $direction === 'credit') {
                $signed = (int)$entry['amount_minor'];
            } elseif ($account === 'contra_income.donation_refund' && $direction === 'debit') {
                $signed = -(int)$entry['amount_minor'];
            } else {
                continue;
            }
            $transaction = $transactions[(string)$entry['transaction_id']] ?? null;
            if (!is_array($transaction)) {
                throw new InvariantViolation('Donation ledger entry has no canonical transaction.');
            }
            $effective = self::date($transaction['effective_at'] ?? null);
            if ($effective > $asOf) {
                continue;
            }
            $lifetime += $signed;
            if ($effective->format('Y-m') === $periodKey) {
                $month += $signed;
            }
            if ($effective->format('Y') === $yearKey) {
                $year += $signed;
            }
            if ($effective > $lastUpdate) {
                $lastUpdate = $effective;
            }
        }
        if ($lifetime < 0 || $month < 0 || $year < 0) {
            throw new InvariantViolation('Net published donation totals cannot be negative.');
        }

        $categoryTotals = [];
        foreach (DonationExpenseCategory::allowed() as $category) {
            $categoryTotals[$category] = 0;
        }
        $expenseEvidence = [];
        foreach ($this->repository->all('expenses') as $expense) {
            if (($expense['currency'] ?? null) !== $currency) {
                continue;
            }
            $occurred = self::date($expense['occurred_at'] ?? null);
            if ($occurred > $asOf) {
                continue;
            }
            $category = (string)$expense['category'];
            DonationExpenseCategory::assertAllowed($category);
            $categoryTotals[$category] += (int)$expense['amount_minor'];
            $expenseEvidence[] = [
                'expense_id' => $expense['expense_id'],
                'amount_minor' => (int)$expense['amount_minor'],
                'currency' => $currency,
                'category' => $category,
                'occurred_at' => $occurred->format(DATE_ATOM),
                'approval_ref' => $expense['approval_ref'],
                'receipt_status' => $expense['receipt_status'],
            ];
            if ($occurred > $lastUpdate) {
                $lastUpdate = $occurred;
            }
        }

        $moneyByCategory = [];
        foreach ($categoryTotals as $category => $minor) {
            $moneyByCategory[$category] = new Money($minor, $currency);
        }
        $sourceMaterial = [
            'period_key' => $periodKey,
            'currency' => $currency,
            'as_of' => $asOf->format(DATE_ATOM),
            'net_lifetime' => $lifetime,
            'net_month' => $month,
            'net_year' => $year,
            'expenses' => $expenseEvidence,
        ];
        $sourceHash = hash('sha256', self::canonicalJson($sourceMaterial));
        $snapshotId = 'snapshot.'.$periodKey.'.'.strtolower($currency);
        $snapshot = new FinancialTransparencySnapshot(
            $snapshotId,
            $asOf,
            new Money($lifetime, $currency),
            new Money($month, $currency),
            new Money($year, $currency),
            $moneyByCategory,
            $lastUpdate,
            $sourceHash
        );
        $public = $snapshot->toPublicProjection();
        $storage = $public + ['source_hash' => $sourceHash];
        $snapshotHash = hash('sha256', self::canonicalJson($storage));
        $existing = $this->repository->get('transparency_snapshots', $snapshotId);
        if ($existing !== null) {
            if (($existing['source_hash'] ?? null) === $sourceHash
                && ($existing['snapshot_hash'] ?? null) === $snapshotHash
                && ($existing['publication_state'] ?? null) === 'published'
            ) {
                return $public + [
                    'source_hash' => $sourceHash,
                    'snapshot_hash' => $snapshotHash,
                    'reused' => true,
                ];
            }
            throw new InvariantViolation('Transparency period and currency already have a different immutable published snapshot.');
        }

        $this->repository->transaction(function () use (
            $snapshotId,
            $periodKey,
            $currency,
            $storage,
            $sourceHash,
            $snapshotHash,
            $asOf,
            $publisherReference
        ): void {
            $this->repository->insert('transparency_snapshots', $snapshotId, [
                'snapshot_id' => $snapshotId,
                'period_key' => $periodKey,
                'currency' => $currency,
                'snapshot_json' => $storage,
                'source_hash' => $sourceHash,
                'snapshot_hash' => $snapshotHash,
                'publication_state' => 'published',
                'published_at' => $asOf,
                'created_at' => $asOf,
            ]);
            $this->audit->append(new AuditEnvelope(
                'audit:transparency:'.substr(hash('sha256', $snapshotId.'|'.$sourceHash), 0, 32),
                $publisherReference,
                'transparency_published',
                'financial_transparency_snapshot',
                $snapshotId,
                'public_aggregate_transparency',
                AuditOutcome::SUCCEEDED,
                $asOf,
                'trace:transparency:'.substr(hash('sha256', $snapshotId), 0, 24),
                [
                    'source_hash' => $sourceHash,
                    'snapshot_hash' => $snapshotHash,
                    'currency' => $currency,
                ]
            ));
        });
        return $public + [
            'source_hash' => $sourceHash,
            'snapshot_hash' => $snapshotHash,
            'reused' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function consentToAcknowledgment(
        string $acknowledgmentId,
        string $donationId,
        string $donorReference,
        string $displayName,
        string $consentText,
        DateTimeImmutable $consentedAt
    ): array {
        foreach ([$acknowledgmentId, $donationId, $donorReference] as $reference) {
            self::assertReference($reference, 'Donor acknowledgment reference');
        }
        $displayName = trim($displayName);
        $consentText = trim($consentText);
        if ($displayName === '' || mb_strlen($displayName) > 191 || $consentText === '' || mb_strlen($consentText) > 2000) {
            throw new InvalidArgumentException('Donor acknowledgment consent is empty or oversized.');
        }
        $donation = $this->repository->get('donations', $donationId);
        if ($donation === null || (string)$donation['donor_ref'] !== $donorReference || ($donation['state'] ?? null) !== 'settled') {
            throw new InvariantViolation('Only the donor of a settled donation may grant public acknowledgment consent.');
        }
        $record = [
            'acknowledgment_id' => $acknowledgmentId,
            'donation_id' => $donationId,
            'donor_ref' => $donorReference,
            'display_name' => $displayName,
            'consent_hash' => hash('sha256', $consentText),
            'consented_at' => $consentedAt,
            'revoked_at' => null,
            'state' => 'active',
        ];
        $this->repository->insert('donor_acknowledgments', $acknowledgmentId, $record);
        return ['acknowledgment_id' => $acknowledgmentId, 'state' => 'active', 'display_name' => $displayName];
    }

    /** @return array<string,mixed> */
    public function revokeAcknowledgment(string $acknowledgmentId, string $donorReference, DateTimeImmutable $revokedAt): array
    {
        $record = $this->repository->get('donor_acknowledgments', $acknowledgmentId);
        if ($record === null || (string)$record['donor_ref'] !== $donorReference) {
            throw new InvariantViolation('Donor acknowledgment was not found in donor scope.');
        }
        $updated = $this->repository->updateWhere('donor_acknowledgments', [
            'acknowledgment_id' => $acknowledgmentId,
            'state' => 'active',
        ], [
            'display_name' => 'Anonymous donor',
            'state' => 'revoked',
            'revoked_at' => $revokedAt,
        ]);
        if ($updated !== 1) {
            throw new InvariantViolation('Donor acknowledgment is already revoked or changed.');
        }
        return [
            'acknowledgment_id' => $acknowledgmentId,
            'state' => 'revoked',
            'revoked_at' => $revokedAt->format(DATE_ATOM),
        ];
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $expected */
    private static function assertExpenseParity(array $existing, array $expected): void
    {
        foreach ([
            'expense_id','amount_minor','currency','category','purpose','payee_ref','approval_ref',
            'receipt_status','founder_related','public_disclosure_category','source_transaction_id',
        ] as $field) {
            if ((string)($existing[$field] ?? '') !== (string)($expected[$field] ?? '')) {
                throw new InvariantViolation('Expense identifier was reused with different financial evidence.');
            }
        }
        if (self::date($existing['occurred_at'] ?? null)->format(DATE_ATOM)
            !== self::date($expected['occurred_at'] ?? null)->format(DATE_ATOM)
        ) {
            throw new InvariantViolation('Expense identifier was reused with a different occurrence time.');
        }
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            throw new InvariantViolation('Financial evidence timestamp is missing.');
        }
        return new DateTimeImmutable($value);
    }

    private static function canonicalJson(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }
            return $item;
        };
        try {
            return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new InvariantViolation('Transparency evidence cannot be canonically encoded.', 0, $error);
        }
    }

    private static function assertReference(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $value) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }
}
