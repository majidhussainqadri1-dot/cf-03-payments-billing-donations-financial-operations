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

        $postedCounts = array_count_values($postedProviderEventIds);
        $duplicates = array_keys(array_filter($postedCounts, static fn (int $count): bool => $count > 1));
        $missing = array_values(array_diff(array_unique($providerEventIds), array_keys($postedCounts)));

        if ($duplicates !== []) {
            throw new InvariantViolation('Restore would duplicate provider event posting.');
        }

        return [
            'balanced' => true,
            'duplicate_provider_events' => $duplicates,
            'missing_provider_events' => $missing,
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
