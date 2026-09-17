<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Infrastructure\WordPressFinancialRepository;
use Sabri\CF03\Infrastructure\WordPressSchemaInstaller;
use Sabri\CF03\Persistence\CompleteSchema;

$tests = [];

$tests['schema identity advanced for canonical repository uniqueness'] = static function (): void {
    if (CompleteSchema::VERSION !== '4.0.2') {
        throw new RuntimeException('Round 10 requires active schema 4.0.2.');
    }
};

$tests['every repository single-id collection has a matching unique single-column database key'] = static function (): void {
    $reflection = new ReflectionClass(WordPressFinancialRepository::class);
    $collections = $reflection->getConstant('COLLECTIONS');
    if (!is_array($collections)) {
        throw new RuntimeException('Canonical repository collection registry is unavailable.');
    }
    $tables = CompleteSchema::tables('wp_');
    foreach ($collections as $collection => $spec) {
        if (!is_array($spec) || !is_string($spec['id'] ?? null)) {
            throw new RuntimeException('Canonical repository collection specification is invalid: '.$collection.'.');
        }
        if (!isset($tables[$collection])) {
            // Retired compatibility mappings intentionally remain fail-closed in the repository registry.
            if (in_array($collection, ['recurring_consents','subscriptions','usage_authorizations','usage_facts'], true)) {
                continue;
            }
            throw new RuntimeException('Active repository collection has no active schema table: '.$collection.'.');
        }
        $idField = $spec['id'];
        $indexes = WordPressSchemaInstaller::requiredIndexes($tables[$collection]);
        $uniqueSingle = false;
        foreach ($indexes as $index) {
            if (($index['unique'] ?? false) === true && ($index['columns'] ?? null) === [$idField]) {
                $uniqueSingle = true;
                break;
            }
        }
        if (!$uniqueSingle) {
            throw new RuntimeException('Repository get(collection,id) is not backed by a unique single-column key: '.$collection.'.'.$idField.'.');
        }
    }
};

$tests['future40 manifest separates immutable amendment baseline from current hardened carrier'] = static function (): void {
    $manifest = json_decode((string)file_get_contents(dirname(__DIR__).'/manifests/cf03-future-expansion-40.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['software_target'] ?? null) !== '1.4.0-rc.1'
        || ($manifest['original_schema_baseline'] ?? null) !== '4.0.0'
        || ($manifest['current_hardened_carrier'] ?? null) !== '1.4.0-rc.3'
        || ($manifest['active_schema_version'] ?? null) !== '4.0.2'
        || ($manifest['feature_count'] ?? null) !== 40
        || ($manifest['activation'] ?? null) !== 'fail_closed_by_default'
    ) {
        throw new RuntimeException('Future40 amendment baseline/current-carrier manifest parity failed.');
    }
};

$tests['current release and contract manifests match plugin carrier and schema'] = static function (): void {
    $root = dirname(__DIR__);
    $release = json_decode((string)file_get_contents($root.'/manifests/cf03-release-1.4.0.json'), true, 512, JSON_THROW_ON_ERROR);
    $contracts = json_decode((string)file_get_contents($root.'/manifests/cf03-contracts.json'), true, 512, JSON_THROW_ON_ERROR);
    $plugin = (string)file_get_contents($root.'/cf-03-payments-billing-donations-financial-operations.php');
    if (($release['software_version'] ?? null) !== '1.4.0-rc.3'
        || ($release['schema_version'] ?? null) !== '4.0.2'
        || ($contracts['software_version'] ?? null) !== '1.4.0-rc.3'
        || ($contracts['active_schema_version'] ?? null) !== '4.0.2'
        || !str_contains($plugin, 'Version: 1.4.0-rc.3')
        || !str_contains($plugin, "define('SABRI_CF03_SCHEMA_VERSION', '4.0.2')")
    ) {
        throw new RuntimeException('Current carrier identity drift detected.');
    }
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS: {$name}\n");
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL: {$name}: {$error->getMessage()}\n");
    }
}

fwrite(STDOUT, count($tests)." tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
