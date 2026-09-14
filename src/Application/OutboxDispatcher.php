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

    /** @return array{delivered:int,retried:int,dead_lettered:int,skipped:int} */
    public function dispatch(DateTimeImmutable $now, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('Outbox dispatch limit is invalid.');
        }

        $result = ['delivered'=>0,'retried'=>0,'dead_lettered'=>0,'skipped'=>0];
        $recovered = $this->recoverExpiredLeases($now);
        $result['retried'] += $recovered['retried'];
        $result['dead_lettered'] += $recovered['dead_lettered'];

        $candidates = array_merge(
            $this->repository->find('outbox', ['state' => 'pending'], $limit),
            $this->repository->find('outbox', ['state' => 'retry'], $limit)
        );
        usort($candidates, static function (array $left, array $right): int {
            $leftAt = self::date($left['available_at'] ?? null)?->getTimestamp() ?? PHP_INT_MAX;
            $rightAt = self::date($right['available_at'] ?? null)?->getTimestamp() ?? PHP_INT_MAX;
            if ($leftAt !== $rightAt) {
                return $leftAt <=> $rightAt;
            }
            return strcmp((string)($left['event_id'] ?? ''), (string)($right['event_id'] ?? ''));
        });

        $processed = 0;
        foreach ($candidates as $message) {
            if ($processed >= $limit) {
                break;
            }
            $availableAt = self::date($message['available_at'] ?? null);
            if ($availableAt === null || $availableAt > $now) {
                $result['skipped']++;
                continue;
            }
            $eventId = (string)($message['event_id'] ?? '');
            $state = (string)($message['state'] ?? '');
            if ($eventId === '' || !in_array($state, ['pending', 'retry'], true)) {
                $result['skipped']++;
                continue;
            }

            $leasedUntil = $now->modify('+5 minutes');
            $leased = $this->repository->updateWhere('outbox', ['event_id'=>$eventId,'state'=>$state], [
                'state'=>'processing','leased_until'=>$leasedUntil,
            ]);
            if ($leased !== 1) {
                $result['skipped']++;
                continue;
            }
            $processed++;

            try {
                $payload = $message['payload_json'] ?? [];
                if (is_string($payload)) {
                    $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                }
                if (!is_array($payload) || array_is_list($payload)) {
                    throw new JsonException('Outbox payload is not an object.');
                }
                $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $storedHash = (string)($message['payload_hash'] ?? '');
                if (preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1
                    || !hash_equals($storedHash, hash('sha256', $encoded))
                ) {
                    throw new InvariantViolation('Outbox payload integrity verification failed.');
                }

                $this->transport->publish($eventId, (string)$message['event_type'], $payload);
                $updated = $this->repository->updateWhere('outbox', [
                    'event_id'=>$eventId,
                    'state'=>'processing',
                    'leased_until'=>$leasedUntil,
                ], [
                    'state'=>'delivered','delivered_at'=>$now,'leased_until'=>null,'last_error_code'=>null,
                ]);
                if ($updated !== 1) {
                    throw new InvariantViolation('Outbox delivery was published but the durable delivery state could not be committed exactly once.');
                }
                $result['delivered']++;
            } catch (Throwable $error) {
                $attempts = (int)($message['attempts'] ?? 0) + 1;
                $dead = $attempts >= $this->maximumAttempts;
                $updated = $this->repository->updateWhere('outbox', [
                    'event_id'=>$eventId,
                    'state'=>'processing',
                    'leased_until'=>$leasedUntil,
                ], [
                    'state'=>$dead?'dead_letter':'retry',
                    'attempts'=>$attempts,
                    'available_at'=>$dead?$now:$now->modify('+'.self::backoffSeconds($attempts).' seconds'),
                    'leased_until'=>null,
                    'last_error_code'=>self::errorCode($error),
                ]);
                if ($updated !== 1) {
                    throw new InvariantViolation('Outbox failure state could not be committed after a delivery attempt.', 0, $error);
                }
                $result[$dead?'dead_lettered':'retried']++;
            }
        }
        return $result;
    }

    /** @return array{retried:int,dead_lettered:int} */
    private function recoverExpiredLeases(DateTimeImmutable $now): array
    {
        $result = ['retried'=>0,'dead_lettered'=>0];
        foreach ($this->repository->find('outbox', ['state'=>'processing'], 500) as $message) {
            $leaseValue = $message['leased_until'] ?? null;
            $leasedUntil = self::date($leaseValue);
            if ($leasedUntil !== null && $leasedUntil > $now) {
                continue;
            }
            $eventId = (string)($message['event_id'] ?? '');
            if ($eventId === '') {
                continue;
            }
            $attempts = (int)($message['attempts'] ?? 0) + 1;
            $dead = $attempts >= $this->maximumAttempts;
            $criteria = ['event_id'=>$eventId,'state'=>'processing','leased_until'=>$leaseValue];
            $updated = $this->repository->updateWhere('outbox', $criteria, [
                'state'=>$dead?'dead_letter':'retry',
                'attempts'=>$attempts,
                'available_at'=>$dead?$now:$now->modify('+'.self::backoffSeconds($attempts).' seconds'),
                'leased_until'=>null,
                'last_error_code'=>'lease_expired',
            ]);
            if ($updated === 1) {
                $result[$dead?'dead_lettered':'retried']++;
            }
        }
        return $result;
    }

    private static function backoffSeconds(int $attempts): int
    {
        return min(3600, 2 ** min(max($attempts, 1), 11));
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
        } catch (Throwable) {
            return null;
        }
    }

    private static function errorCode(Throwable $error): string
    {
        return substr(strtolower(preg_replace('/[^a-z0-9]+/i', '_', $error::class) ?? 'delivery_error'), 0, 64);
    }
}
