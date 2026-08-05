<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use DateTimeImmutable;
use Sabri\CF03\Application\OutboxDispatcher;
use Throwable;

final class WordPressScheduler
{
    public const HOOK='sabri_cf03_process_financial_queue';

    /** @param array<string,mixed> $schedules @return array<string,mixed> */
    public static function schedules(array $schedules): array
    {
        $schedules['sabri_cf03_five_minutes']=['interval'=>300,'display'=>'Every five minutes (CF-03)'];
        return $schedules;
    }

    public static function schedule(): void
    {
        if (function_exists('wp_next_scheduled')&&function_exists('wp_schedule_event')&&!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time()+300,'sabri_cf03_five_minutes',self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) { wp_clear_scheduled_hook(self::HOOK); }
    }

    public static function run(): void
    {
        try {
            $repo=WordPressFinancialRepository::fromWordPress();
            (new OutboxDispatcher($repo,new WordPressOutboxTransport()))->dispatch(new DateTimeImmutable('now'),100);
            $now=new DateTimeImmutable('now');
            foreach ($repo->find('idempotency',['state'=>'pending'],500) as $record) {
                $expires=$record['expires_at']??null;
                try { $date=$expires instanceof DateTimeImmutable?$expires:new DateTimeImmutable((string)$expires); }
                catch (Throwable) { continue; }
                if ($date<=$now) { $repo->updateWhere('idempotency',['idempotency_key'=>$record['idempotency_key'],'state'=>'pending'],['state'=>'failed','completed_at'=>$now]); }
            }
        } catch (Throwable $error) {
            if (function_exists('error_log')) { error_log('CF-03 scheduled processing failed: '.substr($error::class,0,100)); }
        }
    }
}
