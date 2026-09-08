<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class FutureDonationExperienceService
{
    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public function purposeCatalogue(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = trim((string)($row['purpose_id'] ?? ''));
            $name = trim((string)($row['name'] ?? ''));
            if ($id === '' || $name === '') {
                throw new InvalidArgumentException('Purpose ID and name are required.');
            }
            if (($row['entitlement_mapping'] ?? null) !== null || ($row['ranking_signal'] ?? false) === true) {
                throw new InvalidArgumentException('Donation purpose may not map to entitlement or ranking.');
            }
            $out[] = [
                'purpose_id' => $id,
                'name' => $name,
                'accounting_category' => (string)($row['accounting_category'] ?? 'general_support'),
                'active' => (bool)($row['active'] ?? true),
                'preselected' => false,
                'donor_privilege' => false,
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['purpose_id'], $b['purpose_id']));
        return $out;
    }

    /** @return array<string,mixed> */
    public function reminderPreference(string $preference, DateTimeImmutable $from): array
    {
        $days = match ($preference) {
            'seven_days' => 7,
            'thirty_days' => 30,
            'ninety_days' => 90,
            'never' => null,
            default => throw new InvalidArgumentException('Unsupported donation reminder preference.'),
        };
        return [
            'preference' => $preference,
            'minimum_governing_days' => 7,
            'next_eligible_at' => $days === null ? null : $from->add(new DateInterval('P'.$days.'D'))->format(DATE_ATOM),
            'donation_required' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function privacyPreference(string $mode): array
    {
        if (!in_array($mode, ['private', 'anonymous_public', 'aggregate_only'], true)) {
            throw new InvalidArgumentException('Unsupported donation privacy mode.');
        }
        return [
            'mode' => $mode,
            'public_identity' => false,
            'financial_evidence_retained' => true,
            'behavioral_profile_created' => false,
        ];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function receiptVaultEntry(string $receiptId, array $snapshot): array
    {
        if ($receiptId === '') {
            throw new InvalidArgumentException('Receipt ID is required.');
        }
        foreach (['pan', 'cvv', 'cvc', 'pin', 'otp', 'provider_secret', 'bank_password'] as $forbidden) {
            if (array_key_exists($forbidden, $snapshot)) {
                throw new InvalidArgumentException('Sensitive credential material is prohibited in receipt snapshots.');
            }
        }
        $canonical = $this->canonicalJson($snapshot);
        return [
            'receipt_id' => $receiptId,
            'snapshot' => $snapshot,
            'snapshot_sha256' => hash('sha256', $canonical),
            'immutable' => true,
            'download_requires_actor_scope' => true,
        ];
    }

    public function receiptVerificationToken(string $receiptId, string $snapshotHash, string $secret): string
    {
        if (strlen($secret) < 32 || !preg_match('/^[a-f0-9]{64}$/', $snapshotHash)) {
            throw new InvalidArgumentException('Receipt verification requires a strong secret and SHA-256 snapshot hash.');
        }
        return hash_hmac('sha256', $receiptId.'|'.$snapshotHash, $secret);
    }

    public function verifyReceiptToken(string $receiptId, string $snapshotHash, string $secret, string $token): bool
    {
        return hash_equals($this->receiptVerificationToken($receiptId, $snapshotHash, $secret), $token);
    }

    /**
     * @param list<array<string,mixed>> $donations
     * @param list<array<string,mixed>> $refunds
     * @param list<array<string,mixed>> $fees
     * @return array<string,mixed>
     */
    public function transparencySnapshot(array $donations, array $refunds, array $fees): array
    {
        $received = $this->aggregateMoney($donations);
        $refunded = $this->aggregateMoney($refunds);
        $providerFees = $this->aggregateMoney($fees);
        return [
            'received' => $received,
            'refunded' => $refunded,
            'provider_fees' => $providerFees,
            'aggregate_only' => true,
            'donor_identity_included' => false,
            'behavioral_targeting_allowed' => false,
        ];
    }

    /** @param list<array<string,mixed>> $allocations @return array<string,mixed> */
    public function useOfFundsReport(array $allocations): array
    {
        $totals = [];
        foreach ($allocations as $row) {
            $purpose = trim((string)($row['purpose_id'] ?? ''));
            $currency = strtoupper((string)($row['currency'] ?? ''));
            $amount = $row['amount_minor'] ?? null;
            if ($purpose === '' || !is_int($amount) || $amount < 0 || !preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new InvalidArgumentException('Invalid use-of-funds allocation.');
            }
            $totals[$currency][$purpose] = ($totals[$currency][$purpose] ?? 0) + $amount;
        }
        ksort($totals);
        return [
            'allocations' => $totals,
            'accounting_truth_separate' => true,
            'impact_claims_require_evidence' => true,
        ];
    }

    /**
     * @param list<string> $providerCurrencies
     * @param list<string> $jurisdictionCurrencies
     * @return array<string,mixed>
     */
    public function currencyContract(string $currency, array $providerCurrencies, array $jurisdictionCurrencies): array
    {
        $currency = strtoupper($currency);
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be an ISO-style three-letter code.');
        }
        $provider = array_values(array_unique(array_map('strtoupper', $providerCurrencies)));
        $jurisdiction = array_values(array_unique(array_map('strtoupper', $jurisdictionCurrencies)));
        $allowed = in_array($currency, $provider, true) && in_array($currency, $jurisdiction, true);
        $minorUnits = ['JPY' => 0, 'KRW' => 0, 'BHD' => 3, 'KWD' => 3, 'OMR' => 3];
        return [
            'currency' => $currency,
            'allowed' => $allowed,
            'minor_units' => $minorUnits[$currency] ?? 2,
            'automatic_fx' => false,
            'provider_approved' => in_array($currency, $provider, true),
            'jurisdiction_approved' => in_array($currency, $jurisdiction, true),
        ];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int> */
    private function aggregateMoney(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $currency = strtoupper((string)($row['currency'] ?? ''));
            $amount = $row['amount_minor'] ?? null;
            if (!preg_match('/^[A-Z]{3}$/', $currency) || !is_int($amount) || $amount < 0) {
                throw new InvalidArgumentException('Aggregate financial rows require non-negative integer minor units.');
            }
            $totals[$currency] = ($totals[$currency] ?? 0) + $amount;
        }
        ksort($totals);
        return $totals;
    }

    /** @param array<string,mixed> $value */
    private function canonicalJson(array $value): string
    {
        $this->ksortRecursive($value);
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Could not canonicalize financial snapshot.');
        }
        return $json;
    }

    /** @param array<string,mixed> $value */
    private function ksortRecursive(array &$value): void
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->ksortRecursive($item);
            }
        }
    }
}
