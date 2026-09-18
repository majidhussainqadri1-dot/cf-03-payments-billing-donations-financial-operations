<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use InvalidArgumentException;
use Sabri\CF03\Support\InvariantViolation;

final class RestoreReconciliation
{
    /**
     * @param array<string,array{count:int,hash:string}> $expected
     * @param array<string,array{count:int,hash:string}> $restored
     * @param list<string> $providerEventIds
     * @param list<string> $postedProviderEventIds
     * @return array{balanced:bool,duplicate_provider_events:list<string>,missing_provider_events:list<string>}
     */
    public function verify(
        array $expected,
        array $restored,
        array $providerEventIds,
        array $postedProviderEventIds
    ): array {
        foreach ($expected as $name => $manifest) {
            $this->assertManifestRow($name, $manifest);
            if (! isset($restored[$name])) {
                throw new InvariantViolation('Restored financial dataset is missing: ' . $name . '.');
            }
            $this->assertManifestRow($name, $restored[$name]);
            if ($manifest['count'] !== $restored[$name]['count']
                || ! hash_equals($manifest['hash'], $restored[$name]['hash'])
            ) {
                throw new InvariantViolation('Restored financial dataset does not match backup evidence: ' . $name . '.');
            }
        }

        if (!isset($expected['provider_events'], $restored['provider_events'])
            || $expected['provider_events']['count'] !== count($providerEventIds)
            || $restored['provider_events']['count'] !== count($postedProviderEventIds)
        ) {
            throw new InvariantViolation('Provider-authoritative restore evidence count does not match the backed-up/restored provider-event dataset.');
        }
        foreach (array_merge($providerEventIds, $postedProviderEventIds) as $eventId) {
            if (!is_string($eventId)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/', $eventId) !== 1
            ) {
                throw new InvalidArgumentException('Restore provider-event identifier is invalid.');
            }
        }

        $providerCounts = array_count_values($providerEventIds);
        $duplicateAuthoritative = array_keys(array_filter($providerCounts, static fn (int $count): bool => $count > 1));
        if ($duplicateAuthoritative !== []) {
            throw new InvariantViolation('Authoritative provider-event evidence itself contains duplicate identifiers.');
        }

        $postedCounts = array_count_values($postedProviderEventIds);
        $duplicates = array_keys(array_filter($postedCounts, static fn (int $count): bool => $count > 1));
        $missing = array_values(array_diff(array_keys($providerCounts), array_keys($postedCounts)));
        $unexpected = array_values(array_diff(array_keys($postedCounts), array_keys($providerCounts)));

        if ($duplicates !== []) {
            throw new InvariantViolation('Restore would duplicate provider event posting.');
        }
        if ($missing !== []) {
            throw new InvariantViolation('Restore is missing authoritative provider event posting and requires reconciliation.');
        }
        if ($unexpected !== []) {
            throw new InvariantViolation('Restore contains posted provider events absent from authoritative provider evidence.');
        }

        return [
            'balanced' => true,
            'duplicate_provider_events' => [],
            'missing_provider_events' => [],
        ];
    }

    /** @param array{count:int,hash:string} $row */
    private function assertManifestRow(string $name, array $row): void
    {
        if ($name === ''
            || ! isset($row['count'], $row['hash'])
            || ! is_int($row['count'])
            || $row['count'] < 0
            || ! is_string($row['hash'])
            || preg_match('/^[a-f0-9]{64}$/', $row['hash']) !== 1
        ) {
            throw new InvalidArgumentException('Restore manifest row is invalid.');
        }
    }
}
