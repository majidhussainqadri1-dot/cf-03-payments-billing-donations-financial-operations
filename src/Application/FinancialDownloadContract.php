<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

final class FinancialDownloadContract
{
    public const CONTRACT_VERSION = '1.0';
    public const DIRECTIVE_ID = 'CHAT-DL-001';

    /** @return list<string> */
    public static function eligibleAssetTypes(): array
    {
        return ['invoice', 'receipt', 'finance_export', 'transparency_snapshot'];
    }

    /** @return array<string,mixed> */
    public static function contract(): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'directive_id' => self::DIRECTIVE_ID,
            'eligible_asset_types' => self::eligibleAssetTypes(),
            'download_control' => [
                'text_label' => 'Download',
                'icon_family' => 'Ionicons',
                'icon_name' => 'download-outline',
                'primary_accent_token' => 'platform-primary-green',
                'visual_owner' => 'File 25',
                'shell_and_global_manager_owner' => 'File 20',
            ],
            'native_owner' => 'CF-03',
            'assurance_owner' => 'File 24',
            'secure_delivery_owner_after_activation' => 'CF-04',
            'authorization' => [
                'owner_or_explicit_finance_scope_required' => true,
                'revalidate_at_click_time' => true,
                'public_transparency_snapshot_aggregate_only' => true,
                'revoked_expired_or_restricted_hidden_or_denied' => true,
            ],
            'delivery' => [
                'signed_or_same_origin_expiring_grant' => true,
                'safe_filename' => true,
                'checksum_sha256' => true,
                'audit_required' => true,
                'browser_return_is_not_financial_truth' => true,
                'live_delivery_enabled' => false,
            ],
        ];
    }
}
