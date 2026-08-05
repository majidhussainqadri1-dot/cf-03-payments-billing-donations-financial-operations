<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use DateTimeImmutable;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\OutboxDispatcher;
use Sabri\CF03\Application\SecureExportService;
use Throwable;

final class WordPressScheduler
{
    public const HOOK = 'sabri_cf03_process_financial_queue';

    /** @param array<string,mixed> $schedules @return array<string,mixed> */
    public static function schedules(array $schedules): array
    {
        $schedules['sabri_cf03_five_minutes'] = [
            'interval' => 300,
            'display' => 'Every five minutes (CF-03)',
        ];
        return $schedules;
    }

    public static function schedule(): void
    {
        if (function_exists('wp_next_scheduled')
            && function_exists('wp_schedule_event')
            && !wp_next_scheduled(self::HOOK)
        ) {
            wp_schedule_event(time() + 300, 'sabri_cf03_five_minutes', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    public static function run(): void
    {
        try {
            $repo = WordPressFinancialRepository::fromWordPress();
            $now = new DateTimeImmutable('now');
            (new OutboxDispatcher($repo, new WordPressOutboxTransport()))->dispatch($now, 100);

            foreach ($repo->find('idempotency', ['state' => 'pending'], 500) as $record) {
                try {
                    $expires = $record['expires_at'] instanceof DateTimeImmutable
                        ? $record['expires_at']
                        : new DateTimeImmutable((string)$record['expires_at']);
                } catch (Throwable) {
                    continue;
                }
                if ($expires <= $now) {
                    $repo->updateWhere('idempotency', [
                        'idempotency_key' => $record['idempotency_key'],
                        'state' => 'pending',
                    ], [
                        'state' => 'failed',
                        'completed_at' => $now,
                    ]);
                }
            }

            $store = WordPressSecureArtifactStoreFactory::make();
            if (!$store instanceof NullSecureArtifactStore) {
                $exports = new SecureExportService(
                    $repo,
                    $store,
                    WordPressRuntimeConfiguration::load(),
                    new FinancialAuditService($repo)
                );
                foreach ($repo->find('exports', ['state' => 'queued'], 10) as $job) {
                    try {
                        $exports->process(
                            (string)$job['job_id'],
                            'system:cron',
                            (int)$job['version'],
                            $now
                        );
                    } catch (Throwable $error) {
                        self::log('export processing', $error);
                    }
                }
            }
        } catch (Throwable $error) {
            self::log('scheduled processing', $error);
        }
    }

    private static function log(string $operation, Throwable $error): void
    {
        if (function_exists('error_log')) {
            error_log('CF-03 '.$operation.' failed safely: '.substr($error::class, 0, 100));
        }
    }
}
