<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use JsonException;
use Throwable;

final class ActivationEvidenceRecord
{
    public const SCHEMA_VERSION = '1.0';

    /** @var array<string,string> */
    private const REQUIRED_APPROVALS = [
        'founder_change_control' => 'founder_change_control_approved',
        'legal_tax_accounting_review' => 'legal_tax_accounting_review_approved',
        'pci_scope_validation' => 'pci_scope_validated',
        'independent_security_acceptance' => 'independent_security_acceptance',
        'staging_acceptance' => 'staging_acceptance',
        'rollback_rehearsal' => 'rollback_rehearsal_passed',
    ];

    /** @var list<string> */
    private const NON_EXPIRING_APPROVALS = ['founder_change_control'];

    /** @var list<string> */
    private const TOP_LEVEL_KEYS = [
        'schema_version', 'module_version', 'record_id', 'configuration_hash', 'approvals', 'provider',
    ];

    /** @var list<string> */
    private const APPROVAL_KEYS = ['approved', 'evidence_id', 'approver_ref', 'approved_at', 'expires_at'];

    /** @var list<string> */
    private const PROVIDER_KEYS = ['mode', 'provider_ref', 'evidence_id', 'validated_at', 'expires_at'];

    /**
     * @param array<string,mixed> $record
     * @return list<string>
     */
    public static function missingGates(
        array $record,
        string $expectedModuleVersion,
        DateTimeImmutable $now
    ): array {
        $missing = [];

        if (self::hasUnknownKeys($record, self::TOP_LEVEL_KEYS)) {
            $missing[] = 'activation_record_unknown_fields';
        }

        if (($record['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $missing[] = 'activation_record_schema_version';
        }

        if (($record['module_version'] ?? null) !== $expectedModuleVersion) {
            $missing[] = 'activation_record_module_version';
        }

        if (! self::validIdentifier($record['record_id'] ?? null)) {
            $missing[] = 'activation_record_id';
        }

        if (! self::validSha256($record['configuration_hash'] ?? null)) {
            $missing[] = 'activation_configuration_hash';
        }

        $approvals = $record['approvals'] ?? null;
        $evidenceIds = [];
        if (! is_array($approvals)) {
            foreach (self::REQUIRED_APPROVALS as $label) {
                $missing[] = $label;
            }
        } else {
            if (self::hasUnknownKeys($approvals, array_keys(self::REQUIRED_APPROVALS))) {
                $missing[] = 'activation_approvals_unknown_fields';
            }
            foreach (self::REQUIRED_APPROVALS as $key => $label) {
                $block = $approvals[$key] ?? null;
                $requiresExpiry = ! in_array($key, self::NON_EXPIRING_APPROVALS, true);
                if (! self::validApprovalBlock($block, $now, $requiresExpiry)) {
                    $missing[] = $label;
                    continue;
                }

                $evidenceId = (string) $block['evidence_id'];
                if (isset($evidenceIds[$evidenceId])) {
                    $missing[] = 'activation_evidence_duplicate';
                }
                $evidenceIds[$evidenceId] = true;
            }
        }

        $provider = $record['provider'] ?? null;
        if (! self::validProviderBlock($provider, $now)) {
            $missing[] = 'provider_mode';
        } elseif (is_array($provider)) {
            $providerEvidenceId = (string) $provider['evidence_id'];
            if (isset($evidenceIds[$providerEvidenceId])) {
                $missing[] = 'activation_evidence_duplicate';
            }
        }

        return array_values(array_unique($missing));
    }

    /** @param array<string,mixed> $record */
    public static function canonicalHash(array $record): string
    {
        try {
            $encoded = json_encode(
                self::normalize($record),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            return '';
        }

        return hash('sha256', $encoded);
    }

    private static function validApprovalBlock(
        mixed $block,
        DateTimeImmutable $now,
        bool $requiresExpiry
    ): bool {
        if (! is_array($block)
            || self::hasUnknownKeys($block, self::APPROVAL_KEYS)
            || ($block['approved'] ?? false) !== true
        ) {
            return false;
        }

        if (! self::validIdentifier($block['evidence_id'] ?? null)
            || ! self::validIdentifier($block['approver_ref'] ?? null)
        ) {
            return false;
        }

        $approvedAt = self::parseDate($block['approved_at'] ?? null);
        if ($approvedAt === null || $approvedAt > $now->modify('+5 minutes')) {
            return false;
        }

        $hasExpiry = array_key_exists('expires_at', $block) && $block['expires_at'] !== null;
        if ($requiresExpiry && ! $hasExpiry) {
            return false;
        }

        if ($hasExpiry) {
            $expiresAt = self::parseDate($block['expires_at']);
            if ($expiresAt === null
                || $expiresAt <= $now
                || $expiresAt <= $approvedAt
                || $expiresAt > $approvedAt->modify('+366 days')
            ) {
                return false;
            }
        }

        return true;
    }

    private static function validProviderBlock(mixed $block, DateTimeImmutable $now): bool
    {
        if (! is_array($block) || self::hasUnknownKeys($block, self::PROVIDER_KEYS)) {
            return false;
        }

        if (! in_array($block['mode'] ?? null, ['hosted', 'tokenized'], true)) {
            return false;
        }

        if (! self::validIdentifier($block['provider_ref'] ?? null)
            || ! self::validIdentifier($block['evidence_id'] ?? null)
        ) {
            return false;
        }

        $validatedAt = self::parseDate($block['validated_at'] ?? null);
        $expiresAt = self::parseDate($block['expires_at'] ?? null);
        if ($validatedAt === null
            || $validatedAt > $now->modify('+5 minutes')
            || $expiresAt === null
            || $expiresAt <= $now
            || $expiresAt <= $validatedAt
            || $expiresAt > $validatedAt->modify('+90 days')
        ) {
            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $value @param list<string> $allowed */
    private static function hasUnknownKeys(array $value, array $allowed): bool
    {
        foreach (array_keys($value) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                return true;
            }
        }
        return false;
    }

    private static function validIdentifier(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $value) === 1;
    }

    private static function validSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
