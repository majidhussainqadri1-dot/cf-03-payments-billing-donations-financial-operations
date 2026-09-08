<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;
use RuntimeException;

final class FutureGovernancePrivacyService
{
    /** @param array<string,array<string,mixed>> $categories @return array<string,mixed> */
    public function financialPrivacyCenter(array $categories): array
    {
        $safe = [];
        foreach ($categories as $name => $meta) {
            if (in_array(strtolower((string)$name), ['pan','cvv','cvc','pin','otp','password','provider_secret'], true)) {
                throw new InvalidArgumentException('Credential material may not appear in the financial privacy center.');
            }
            $safe[$name] = [
                'purpose' => (string)($meta['purpose'] ?? 'financial_record'),
                'retention' => (string)($meta['retention'] ?? 'policy_defined'),
                'exportable' => (bool)($meta['exportable'] ?? true),
                'erasable' => (bool)($meta['erasable'] ?? false),
                'legal_hold_possible' => (bool)($meta['legal_hold_possible'] ?? true),
            ];
        }
        ksort($safe);
        return ['categories' => $safe, 'provider_secrets_exposed' => false];
    }

    /** @return array<string,mixed> */
    public function userFinanceExport(string $actorRef, array $includedTypes, int $ttlSeconds = 3600): array
    {
        if ($actorRef === '' || $ttlSeconds < 300 || $ttlSeconds > 86400) {
            throw new InvalidArgumentException('Invalid user finance export scope or expiry.');
        }
        $allowed = ['receipt','donation','refund','dispute'];
        $types = array_values(array_intersect($allowed, array_values(array_unique($includedTypes))));
        return [
            'actor_ref' => $actorRef,
            'types' => $types,
            'encrypted' => true,
            'temporary' => true,
            'expires_in_seconds' => $ttlSeconds,
            'download_audited' => true,
        ];
    }

    /** @return array<string,mixed> */
    public function accountantExport(array $fields, string $currency, string $dateFrom, string $dateTo): array
    {
        $allowed = ['transaction_id','effective_at','account','direction','amount_minor','currency','source_type','source_ref','period_id'];
        $selected = array_values(array_intersect($allowed, array_values(array_unique($fields))));
        if ($selected === []) {
            throw new InvalidArgumentException('Accountant export requires at least one approved field.');
        }
        return [
            'fields' => $selected,
            'currency' => strtoupper($currency),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'formula_injection_neutralization' => true,
            'checksum_manifest' => true,
            'provider_secrets_included' => false,
        ];
    }

    public function neutralizeSpreadsheetCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }
        return $value;
    }

    /** @param array<string,string> $hashes @return array<string,mixed> */
    public function auditEvidencePackage(string $periodId, array $hashes, array $approvals): array
    {
        $required = ['ledger','reconciliation','configuration','backup'];
        foreach ($required as $key) {
            if (!isset($hashes[$key]) || !preg_match('/^[a-f0-9]{64}$/', $hashes[$key])) {
                throw new InvalidArgumentException('Audit package requires SHA-256 hash for '.$key.'.');
            }
        }
        ksort($hashes);
        sort($approvals);
        $payload = json_encode([$periodId, $hashes, $approvals], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            throw new RuntimeException('Could not build audit evidence package.');
        }
        return [
            'period_id' => $periodId,
            'hashes' => $hashes,
            'approvals' => $approvals,
            'package_sha256' => hash('sha256', $payload),
            'immutable' => true,
        ];
    }

    /** @param array<string,mixed> $configuration @return array<string,mixed> */
    public function configurationVersion(string $versionId, array $configuration, string $approvalRef): array
    {
        if ($versionId === '' || $approvalRef === '') {
            throw new InvalidArgumentException('Configuration version and approval reference are required.');
        }
        ksort($configuration);
        $json = json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode financial configuration.');
        }
        return [
            'version_id' => $versionId,
            'configuration' => $configuration,
            'approval_ref' => $approvalRef,
            'snapshot_sha256' => hash('sha256', $json),
            'immutable' => true,
        ];
    }

    /** @param list<array<string,mixed>> $scenarios @return array<string,mixed> */
    public function policySimulation(array $policy, array $scenarios): array
    {
        $results = [];
        foreach ($scenarios as $index => $scenario) {
            $currency = strtoupper((string)($scenario['currency'] ?? ''));
            $country = strtoupper((string)($scenario['country'] ?? ''));
            $results[] = [
                'scenario' => $index + 1,
                'currency' => $currency,
                'country' => $country,
                'would_allow' => in_array($currency, (array)($policy['currencies'] ?? []), true)
                    && in_array($country, (array)($policy['countries'] ?? []), true),
            ];
        }
        return ['simulation_only' => true, 'production_mutation' => false, 'results' => $results];
    }

    /** @return array<string,mixed> */
    public function sandboxScenario(string $scenario): array
    {
        $allowed = [
            'donation_success',
            'provider_timeout',
            'duplicate_webhook',
            'delayed_settlement',
            'refund_timeout',
            'chargeback',
            'provider_outage',
            'restore_replay',
        ];
        if (!in_array($scenario, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported finance sandbox scenario.');
        }
        return [
            'scenario' => $scenario,
            'sandbox_only' => true,
            'real_money_allowed' => false,
            'production_provider_allowed' => false,
            'deterministic_fixture_required' => true,
        ];
    }

    /** @param array<string,bool> $gates @return array<string,mixed> */
    public function deploymentReadiness(array $gates): array
    {
        $required = [
            'provider',
            'webhook',
            'legal',
            'tax',
            'accounting',
            'pci',
            'security',
            'staging',
            'cross_file_integration',
            'backup_restore',
            'rollback',
            'founder_approval',
        ];
        $missing = [];
        foreach ($required as $gate) {
            if (($gates[$gate] ?? false) !== true) {
                $missing[] = $gate;
            }
        }
        return [
            'ready' => $missing === [],
            'collection_fail_closed' => $missing !== [],
            'missing_gates' => $missing,
            'required_gates' => $required,
        ];
    }

    /** @param array<string,bool> $requested @return array<string,bool> */
    public function killSwitchState(array $requested): array
    {
        $defaults = [
            'new_donations' => false,
            'refund_execution' => false,
            'provider_calls' => false,
            'webhooks' => false,
            'exports' => false,
        ];
        foreach ($defaults as $key => $value) {
            if (array_key_exists($key, $requested)) {
                $defaults[$key] = (bool)$requested[$key];
            }
        }
        return $defaults;
    }

    /** @param array<string,string|int> $before @param array<string,string|int> $after @return array<string,mixed> */
    public function restoreVerification(array $before, array $after): array
    {
        $keys = ['ledger_count','ledger_hash','receipt_count','receipt_hash','outbox_count','outbox_hash','dedupe_count','dedupe_hash'];
        $mismatches = [];
        foreach ($keys as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $mismatches[] = $key;
            }
        }
        return [
            'verified' => $mismatches === [],
            'writes_may_reopen' => $mismatches === [],
            'mismatches' => $mismatches,
        ];
    }
}
