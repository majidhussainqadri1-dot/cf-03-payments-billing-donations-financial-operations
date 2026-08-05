<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;

final class BillingQueryService
{
    /** @var array<string,array{collection:string,field:string}> */
    private const GROUPS = [
        'invoices' => ['collection' => 'invoices', 'field' => 'actor_ref'],
        'donations' => ['collection' => 'donations', 'field' => 'donor_ref'],
        'subscriptions' => ['collection' => 'subscriptions', 'field' => 'actor_ref'],
        'refunds' => ['collection' => 'refunds', 'field' => 'requester_ref'],
        'exports' => ['collection' => 'exports', 'field' => 'requester_ref'],
    ];

    public function __construct(private readonly QueryableFinancialRepository $repository) {}

    /** @return array<string,mixed> */
    public function forActor(string $actorReference, int $limit = 50): array
    {
        self::assertReference($actorReference);
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Billing query limit must be between 1 and 100.');
        }
        $groups = [];
        foreach (self::GROUPS as $name => $definition) {
            $groups[$name] = $this->safe(
                $name,
                $this->repository->find(
                    $definition['collection'],
                    [$definition['field'] => $actorReference],
                    $limit
                )
            );
        }
        return $this->response($groups, null);
    }

    /** @return array<string,mixed> */
    public function forActorPage(string $actorReference, int $page, int $perGroup = 20): array
    {
        self::assertReference($actorReference);
        if ($page < 1 || $page > 100000) {
            throw new InvalidArgumentException('Billing export page is invalid.');
        }
        if ($perGroup < 1 || $perGroup > 100) {
            throw new InvalidArgumentException('Billing export page size must be between 1 and 100.');
        }
        $offset = ($page - 1) * $perGroup;
        $groups = [];
        $done = true;
        foreach (self::GROUPS as $name => $definition) {
            $records = $this->repository->page(
                $definition['collection'],
                [$definition['field'] => $actorReference],
                $perGroup,
                $offset
            );
            $groups[$name] = $this->safe($name, $records);
            if (count($records) === $perGroup) {
                $done = false;
            }
        }
        return $this->response($groups, $done);
    }

    /** @param array<string,list<array<string,mixed>>> $groups @return array<string,mixed> */
    private function response(array $groups, ?bool $done): array
    {
        $counts = [];
        foreach ($groups as $name => $records) {
            $counts[$name] = count($records);
        }
        $response = ['actor_scope' => 'self'] + $groups + ['counts' => $counts];
        if ($done !== null) {
            $response['done'] = $done;
        }
        return $response;
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
            foreach ($allowed as $field) {
                if (array_key_exists($field, $record)) {
                    $safe[$field] = $record[$field];
                }
            }
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
