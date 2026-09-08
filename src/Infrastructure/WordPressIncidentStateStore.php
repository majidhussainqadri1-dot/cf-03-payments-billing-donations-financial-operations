<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Sabri\CF03\Contracts\IncidentStateStore;

final class WordPressIncidentStateStore implements IncidentStateStore
{
    public const OPTION = 'sabri_cf03_incident_state';

    /** @return array<string,mixed> */
    public function get(): array
    {
        if (!function_exists('get_option')) {
            return self::corrupt();
        }
        $stored = get_option(self::OPTION, null);
        if ($stored === null || $stored === false) {
            return self::normal();
        }
        if (!is_array($stored)) {
            return self::corrupt();
        }
        try {
            return self::normalize($stored);
        } catch (InvalidArgumentException) {
            return self::corrupt();
        }
    }

    /** @param array<string,mixed> $state */
    public function save(array $state): void
    {
        if (!function_exists('update_option') || !function_exists('get_option')) {
            throw new RuntimeException('WordPress incident state storage is unavailable.');
        }
        $normalized = self::normalize($state);
        $updated = update_option(self::OPTION, $normalized, false);
        if ($updated === false) {
            $current = get_option(self::OPTION, null);
            if (!is_array($current) || self::normalize($current) !== $normalized) {
                throw new RuntimeException('WordPress incident state could not be persisted.');
            }
        }
    }

    /** @return array<string,mixed> */
    public static function normal(): array
    {
        return [
            'state' => 'normal',
            'incident_id' => null,
            'severity' => null,
            'reason_code' => null,
            'checkout_enabled' => true,
            'refunds_enabled' => true,
            'webhooks_enabled' => true,
            'declared_at' => null,
            'declared_by' => null,
            'recovered_at' => null,
            'recovered_by' => null,
            'resolution_evidence_ref' => null,
            'record_version' => 1,
        ];
    }

    /** @return array<string,mixed> */
    public static function corrupt(): array
    {
        return [
            'state' => 'corrupt',
            'incident_id' => null,
            'severity' => null,
            'reason_code' => 'invalid_persisted_state',
            'checkout_enabled' => false,
            'refunds_enabled' => false,
            'webhooks_enabled' => false,
            'declared_at' => null,
            'declared_by' => null,
            'recovered_at' => null,
            'recovered_by' => null,
            'resolution_evidence_ref' => null,
            'record_version' => 1,
        ];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function normalize(array $state): array
    {
        $allowed = array_keys(self::normal());
        if (array_diff(array_keys($state), $allowed) !== []) {
            throw new InvalidArgumentException('Incident state contains an unknown field.');
        }
        $normalized = array_replace(self::normal(), $state);
        $mode = $normalized['state'];
        if (!is_string($mode) || !in_array($mode, ['normal', 'contained', 'recovered'], true)) {
            throw new InvalidArgumentException('Incident mode is invalid.');
        }
        foreach (['checkout_enabled', 'refunds_enabled', 'webhooks_enabled'] as $flag) {
            if (!is_bool($normalized[$flag])) {
                throw new InvalidArgumentException('Incident path flag is invalid.');
            }
        }
        if (!is_int($normalized['record_version']) || $normalized['record_version'] < 1) {
            throw new InvalidArgumentException('Incident record version is invalid.');
        }
        if ($mode === 'normal') {
            return array_replace(self::normal(), ['record_version' => $normalized['record_version']]);
        }
        if (!is_int($normalized['severity']) || $normalized['severity'] < 0 || $normalized['severity'] > 4) {
            throw new InvalidArgumentException('Incident severity is invalid.');
        }
        self::reference($normalized['incident_id'], 'Incident ID');
        self::reference($normalized['declared_by'], 'Incident declarer');
        self::code($normalized['reason_code']);
        self::date($normalized['declared_at'], 'Incident declaration time');

        if ($mode === 'contained') {
            foreach (['recovered_at', 'recovered_by', 'resolution_evidence_ref'] as $field) {
                if ($normalized[$field] !== null) {
                    throw new InvalidArgumentException('Contained incident cannot contain recovery evidence.');
                }
            }
        } else {
            self::reference($normalized['recovered_by'], 'Incident recovery approver');
            self::reference($normalized['resolution_evidence_ref'], 'Incident recovery evidence');
            $declared = self::date($normalized['declared_at'], 'Incident declaration time');
            $recovered = self::date($normalized['recovered_at'], 'Incident recovery time');
            if ($recovered <= $declared) {
                throw new InvalidArgumentException('Incident recovery must follow declaration.');
            }
            if ($normalized['checkout_enabled'] && !$normalized['webhooks_enabled']) {
                throw new InvalidArgumentException('Recovered checkout requires the trusted webhook path.');
            }
        }
        return $normalized;
    }

    private static function reference(mixed $value, string $label): void
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $value) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }

    private static function code(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_]{2,63}$/', $value) !== 1) {
            throw new InvalidArgumentException('Incident reason code is invalid.');
        }
    }

    private static function date(mixed $value, string $label): DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException($label.' is missing.');
        }
        $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        if (!$date instanceof DateTimeImmutable || $date->format(DATE_ATOM) !== $value) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
        return $date;
    }
}
