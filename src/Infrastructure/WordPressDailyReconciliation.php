<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use DateTimeImmutable;
use Sabri\CF03\Application\DailyReconciliationService;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\SettlementOperationsService;
use Throwable;

final class WordPressDailyReconciliation
{
    public const HOOK = 'sabri_cf03_daily_reconciliation';
    public const OPTION_LAST_COMPLETED_DATE = 'sabri_cf03_reconciliation_last_completed_date';
    public const OPTION_LAST_RESULT = 'sabri_cf03_reconciliation_last_result';

    public static function schedule(): void
    {
        if (function_exists('wp_next_scheduled')
            && function_exists('wp_schedule_event')
            && !wp_next_scheduled(self::HOOK)
        ) {
            wp_schedule_event(time() + 3600, 'daily', self::HOOK);
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
            $runtime = WordPressRuntimeConfiguration::load();
            $runtime->assertFinancialMutationReady();
            $yesterday = (new DateTimeImmutable('now'))->modify('-1 day')->format('Y-m-d');
            $last = function_exists('get_option')
                ? get_option(self::OPTION_LAST_COMPLETED_DATE, '')
                : '';
            $from = is_string($last) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $last) === 1
                ? (new DateTimeImmutable($last))->modify('+1 day')->format('Y-m-d')
                : $yesterday;
            if ($from > $yesterday) {
                return;
            }

            $repository = WordPressFinancialRepository::fromWordPress();
            $audit = new FinancialAuditService($repository);
            $service = new DailyReconciliationService(
                $repository,
                WordPressProviderRegistryFactory::payments(),
                $runtime,
                new SettlementOperationsService($repository, $runtime, $audit)
            );
            $materiality = function_exists('apply_filters')
                ? apply_filters('sabri_cf03_reconciliation_materiality', ['USD' => 1, 'PKR' => 1])
                : ['USD' => 1, 'PKR' => 1];
            if (!is_array($materiality)) {
                throw new \RuntimeException('Daily reconciliation materiality configuration is invalid.');
            }
            $result = $service->run($from, $yesterday, 'system:daily-reconciliation', $materiality);
            if (function_exists('update_option')) {
                update_option(self::OPTION_LAST_COMPLETED_DATE, $yesterday, false);
                update_option(self::OPTION_LAST_RESULT, $result + ['completed_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM)], false);
            }
        } catch (Throwable $error) {
            if (function_exists('error_log')) {
                error_log('CF-03 daily reconciliation failed safely: '.substr($error::class, 0, 100));
            }
        }
    }
}
