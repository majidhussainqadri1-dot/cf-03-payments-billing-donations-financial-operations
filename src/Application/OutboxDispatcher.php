<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use JsonException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Throwable;

final class OutboxDispatcher
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly OutboxTransport $transport,
        private readonly int $maximumAttempts = 10
    ) {
        if ($maximumAttempts < 1 || $maximumAttempts > 100) { throw new \InvalidArgumentException('Outbox maximum attempts is invalid.'); }
    }

    /** @return array{delivered:int,retried:int,dead_lettered:int,skipped:int} */
    public function dispatch(DateTimeImmutable $now, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 200) { throw new \InvalidArgumentException('Outbox dispatch limit is invalid.'); }
        $candidates = array_merge(
            $this->repository->find('outbox', ['state' => 'pending'], $limit),
            $this->repository->find('outbox', ['state' => 'retry'], $limit)
        );
        $result = ['delivered'=>0,'retried'=>0,'dead_lettered'=>0,'skipped'=>0];
        $processed = 0;
        foreach ($candidates as $message) {
            if ($processed >= $limit) { break; }
            $availableAt = self::date($message['available_at'] ?? null);
            if ($availableAt === null || $availableAt > $now) { $result['skipped']++; continue; }
            $eventId = (string)($message['event_id'] ?? '');
            $state = (string)($message['state'] ?? '');
            $leased = $this->repository->updateWhere('outbox', ['event_id'=>$eventId,'state'=>$state], [
                'state'=>'processing','leased_until'=>$now->modify('+5 minutes'),
            ]);
            if ($leased !== 1) { $result['skipped']++; continue; }
            $processed++;
            try {
                $payload = $message['payload_json'] ?? [];
                if (is_string($payload)) { $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR); }
                if (!is_array($payload)) { throw new JsonException('Outbox payload is not an object.'); }
                $this->transport->publish($eventId, (string)$message['event_type'], $payload);
                $this->repository->updateWhere('outbox', ['event_id'=>$eventId,'state'=>'processing'], [
                    'state'=>'delivered','delivered_at'=>$now,'leased_until'=>null,'last_error_code'=>null,
                ]);
                $result['delivered']++;
            } catch (Throwable $error) {
                $attempts = (int)($message['attempts'] ?? 0) + 1;
                $dead = $attempts >= $this->maximumAttempts;
                $this->repository->updateWhere('outbox', ['event_id'=>$eventId,'state'=>'processing'], [
                    'state'=>$dead?'dead_letter':'retry',
                    'attempts'=>$attempts,
                    'available_at'=>$dead?$now:$now->modify('+'.min(3600, 2 ** min($attempts, 11)).' seconds'),
                    'leased_until'=>null,
                    'last_error_code'=>self::errorCode($error),
                ]);
                $result[$dead?'dead_lettered':'retried']++;
            }
        }
        return $result;
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
