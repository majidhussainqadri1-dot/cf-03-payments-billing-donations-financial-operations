<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use JsonException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class OutboxDispatcher
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly OutboxTransport $transport,
        private readonly int $maximumAttempts = 10
    ) {
        if ($maximumAttempts < 1 || $maximumAttempts > 100) {
            throw new \InvalidArgumentException('Outbox maximum attempts is invalid.');
        }
    }

    /** @return array{delivered:int,retried:int,dead_lettered:int,skipped:int,recovered_leases:int} */
    public function dispatch(DateTimeImmutable $now, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('Outbox dispatch limit is invalid.');
        }

        $candidates = array_merge(
            $this->repository->find('outbox', ['state' => 'pending'], $limit),
            $this->repository->find('outbox', ['state' => 'retry'], $limit),
            $this->expiredProcessingCandidates($now, $limit)
        );
        $result = ['delivered'=>0,'retried'=>0,'dead_lettered'=>0,'skipped'=>0,'recovered_leases'=>0];
        $processed = 0;
        $seen = [];

        foreach ($candidates as $message) {
            if ($processed >= $limit) { break; }
            $eventId = (string)($message['event_id'] ?? '');
            if ($eventId === '' || isset($seen[$eventId])) {
                $result['skipped']++;
                continue;
            }
            $seen[$eventId] = true;

            $availableAt = self::date($message['available_at'] ?? null);
            if ($availableAt === null || $availableAt > $now) {
                $result['skipped']++;
                continue;
            }

            $state = (string)($message['state'] ?? '');
            $criteria = ['event_id'=>$eventId,'state'=>$state];
            if ($state === 'processing') {
                $leasedUntil = self::date($message['leased_until'] ?? null);
                if ($leasedUntil === null || $leasedUntil > $now) {
                    $result['skipped']++;
                    continue;
                }
                $criteria['leased_until'] = $message['leased_until'];
            }

            $leased = $this->repository->updateWhere('outbox', $criteria, [
                'state'=>'processing',
                'leased_until'=>$now->modify('+5 minutes'),
            ]);
            if ($leased !== 1) {
                $result['skipped']++;
                continue;
            }
            if ($state === 'processing') {
                $result['recovered_leases']++;
            }
            $processed++;

            try {
                $payload = $message['payload_json'] ?? [];
                if (is_string($payload)) {
                    $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                }
                if (!is_array($payload)) {
                    throw new JsonException('Outbox payload is not an object.');
                }

                // Delivery is deliberately at-least-once. Consumers receive eventId as the
                // deduplication key and MUST be idempotent. An expired lease may therefore be
                // replayed after a worker crash rather than being silently stranded forever.
                $this->transport->publish($eventId, (string)$message['event_type'], $payload);
                $acknowledged = $this->repository->updateWhere('outbox', [
                    'event_id'=>$eventId,
                    'state'=>'processing',
                ], [
                    'state'=>'delivered',
                    'delivered_at'=>$now,
                    'leased_until'=>null,
                    'last_error_code'=>null,
                ]);
                if ($acknowledged !== 1) {
                    throw new InvariantViolation('Outbox delivery could not be durably acknowledged.');
                }
                $result['delivered']++;
            } catch (Throwable $error) {
                $attempts = (int)($message['attempts'] ?? 0) + 1;
                $dead = $attempts >= $this->maximumAttempts;
                $released = $this->repository->updateWhere('outbox', [
                    'event_id'=>$eventId,
                    'state'=>'processing',
                ], [
                    'state'=>$dead?'dead_letter':'retry',
                    'attempts'=>$attempts,
                    'available_at'=>$dead?$now:$now->modify('+'.min(3600, 2 ** min($attempts, 11)).' seconds'),
                    'leased_until'=>null,
                    'last_error_code'=>self::errorCode($error),
                ]);
                if ($released !== 1) {
                    throw new InvariantViolation('Outbox failure state could not be durably recorded.', 0, $error);
                }
                $result[$dead?'dead_lettered':'retried']++;
            }
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function expiredProcessingCandidates(DateTimeImmutable $now, int $limit): array
    {
        $expired = [];
        foreach ($this->repository->find('outbox', ['state'=>'processing'], $limit) as $message) {
            $leasedUntil = self::date($message['leased_until'] ?? null);
            if ($leasedUntil !== null && $leasedUntil <= $now) {
                $expired[] = $message;
            }
        }
        return $expired;
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) { return $value; }
        if (!is_string($value) || $value === '') { return null; }
        try { return new DateTimeImmutable($value); } catch (Throwable) { return null; }
    }

    private static function errorCode(Throwable $error): string
    {
        return substr(strtolower(preg_replace('/[^a-z0-9]+/i', '_', $error::class) ?? 'delivery_error'), 0, 64);
    }
}
