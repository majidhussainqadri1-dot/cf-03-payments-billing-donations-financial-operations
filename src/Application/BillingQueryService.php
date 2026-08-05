<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;

final class BillingQueryService
{
    public function __construct(private readonly QueryableFinancialRepository $repository) {}

    /** @return array<string,mixed> */
    public function forActor(string $actorReference, int $limit = 50): array
    {
        self::assertReference($actorReference);
        if ($limit < 1 || $limit > 100) { throw new InvalidArgumentException('Billing query limit must be between 1 and 100.'); }
        $invoices = $this->safe('invoices', $this->repository->find('invoices', ['actor_ref' => $actorReference], $limit));
        $donations = $this->safe('donations', $this->repository->find('donations', ['donor_ref' => $actorReference], $limit));
        $subscriptions = $this->safe('subscriptions', $this->repository->find('subscriptions', ['actor_ref' => $actorReference], $limit));
        $refunds = $this->safe('refunds', $this->repository->find('refunds', ['requester_ref' => $actorReference], $limit));
        $exports = $this->safe('exports', $this->repository->find('exports', ['requester_ref' => $actorReference], $limit));
        return [
            'actor_scope' => 'self',
            'invoices' => $invoices,
            'donations' => $donations,
            'subscriptions' => $subscriptions,
            'refunds' => $refunds,
            'exports' => $exports,
            'counts' => [
                'invoices' => count($invoices), 'donations' => count($donations),
                'subscriptions' => count($subscriptions), 'refunds' => count($refunds), 'exports' => count($exports),
            ],
        ];
    }

    /** @param list<array<string,mixed>> $records @return list<array<string,mixed>> */
    private function safe(string $collection, array $records): array
    {
        $allowed = [
            'invoices' => ['invoice_id','invoice_number','status','amount_minor','currency','snapshot_hash','issued_at','voided_at'],
            'donations' => ['donation_id','amount_minor','currency','purpose_code','recurring','receipt_ref','state','created_at','updated_at'],
            'subscriptions' => ['subscription_id','product_id','price_version_id','state','current_period_end','grace_until','paused_until','cancellation_effective_at','policy_version','created_at','updated_at'],
            'refunds' => ['refund_id','intent_id','amount_minor','currency','reason','decision_reason','state','requested_at','updated_at'],
            'exports' => ['job_id','specification_hash','maximum_rows','state','manifest_hash','expires_at','created_at','updated_at'],
        ][$collection] ?? [];
        $result = [];
        foreach ($records as $record) {
            $safe = [];
            foreach ($allowed as $field) { if (array_key_exists($field, $record)) { $safe[$field] = $record[$field]; } }
            $result[] = $safe;
        }
        return $result;
    }

    private static function assertReference(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,190}$/', $value) !== 1) {
            throw new InvalidArgumentException('Billing actor reference is invalid.');
        }
    }
}
