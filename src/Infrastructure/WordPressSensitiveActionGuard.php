<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

/**
 * Fail-closed bridge to File 02 recent-auth / step-up assurance plus toxic-capability checks.
 * CF-03 never treats possession of a WordPress role alone as sufficient for a high-risk action.
 */
final class WordPressSensitiveActionGuard
{
    /** @var list<array{0:string,1:string}> */
    private const TOXIC_PAIRS = [
        ['sabri_review_refunds', 'sabri_execute_refunds'],
        ['sabri_import_settlements', 'sabri_close_finance'],
        ['sabri_reconcile_finance', 'sabri_close_finance'],
        ['sabri_import_settlements', 'sabri_view_finance_audit'],
        ['sabri_execute_refunds', 'sabri_view_finance_audit'],
        ['sabri_manage_finance_adjustments', 'sabri_view_finance_audit'],
        ['sabri_manage_finance_risk', 'sabri_view_finance_audit'],
        ['sabri_manage_finance_incidents', 'sabri_view_finance_audit'],
    ];

    public static function can(string $capability, string $purpose): bool
    {
        if (!function_exists('current_user_can')
            || !function_exists('get_current_user_id')
            || !function_exists('apply_filters')
            || !current_user_can($capability)
        ) {
            return false;
        }
        $userId = (int)get_current_user_id();
        if ($userId < 1 || preg_match('/^[a-z][a-z0-9_.:-]{2,95}$/', $purpose) !== 1) {
            return false;
        }

        foreach (self::TOXIC_PAIRS as [$left, $right]) {
            if (current_user_can($left) && current_user_can($right)) {
                return false;
            }
        }

        // File 02 / the deployment's authentication owner must explicitly attest that
        // this actor recently completed the required step-up. No hook means denial.
        $approved = apply_filters('sabri_cf03_recent_auth_approved', false, $purpose, $userId);
        return $approved === true;
    }
}
