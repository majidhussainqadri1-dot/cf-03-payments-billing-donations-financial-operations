<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use Sabri\CF03\Contracts\QueryableFinancialRepository;

final class FinancialOperationsDashboard
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly ProviderRegistry $providers,
        private readonly SystemIntegrityService $integrity
    ) {}

    /** @return array<string,mixed> */
    public function snapshot(DateTimeImmutable $now): array
    {
        $chargebacksDue = 0;
        $deadline = $now->modify('+7 days');
        foreach ($this->repository->all('chargebacks') as $case) {
            if (in_array((string)($case['state'] ?? ''), ['closed','won','lost','ledger_adjusted'], true)) {
                continue;
            }
            $due = self::date($case['response_deadline'] ?? null);
            if ($due !== null && $due <= $deadline) {
                $chargebacksDue++;
            }
        }

        $health = $this->integrity->health();
        return [
            'generated_at' => $now->format(DATE_ATOM),
            'privacy' => [
                'aggregate_only' => true,
                'contains_actor_identity' => false,
                'contains_provider_secrets' => false,
            ],
            'providers' => $this->providers->health(),
            'payment_intents' => $this->stateCounts('intents', 'state'),
            'provider_events' => $this->stateCounts('provider_events', 'status'),
            'subscriptions' => $this->stateCounts('subscriptions', 'state'),
            'refunds' => $this->stateCounts('refunds', 'state'),
            'chargebacks' => $this->stateCounts('chargebacks', 'state') + ['due_within_seven_days' => $chargebacksDue],
            'settlements' => $this->stateCounts('settlements', 'status'),
            'reconciliation' => [
                'open' => count($this->repository->find('reconciliation_exceptions', ['state' => 'open'], 500)),
                'material_open' => (int)$health['open_material_exceptions'],
            ],
            'outbox' => $this->stateCounts('outbox', 'state'),
            'exports' => $this->stateCounts('exports', 'state'),
            'integrity' => [
                'ledger_balanced' => $health['ledger_balanced'],
                'audit_chain_valid' => $health['audit_chain_valid'],
                'dead_letter_count' => $health['dead_letter_count'],
                'balanced_transaction_currency_pairs' => $health['balanced_transaction_currency_pairs'],
            ],
        ];
    }

    /** @return array<string,int> */
    private function stateCounts(string $collection, string $field): array
    {
        $counts = [];
        foreach ($this->repository->all($collection) as $record) {
            $state = is_string($record[$field] ?? null) && $record[$field] !== ''
                ? $record[$field]
                : 'unknown';
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);
        return $counts;
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
