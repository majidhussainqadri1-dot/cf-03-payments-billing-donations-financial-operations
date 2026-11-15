<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FutureExpansionRegistry;

$root = dirname(__DIR__);
$manifest = json_decode((string)file_get_contents(
    $root.'/manifests/cf03-v123-requirements.json'
), true, 512, JSON_THROW_ON_ERROR);
$previousFuture = json_decode((string)file_get_contents(
    $root.'/manifests/cf03-future-expansion-40.json'
), true, 512, JSON_THROW_ON_ERROR);
$trace = (string)file_get_contents($root.'/docs/requirements-traceability.md');

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException('FAIL: '.$label); }
};

$assert(($manifest['source_plan'] ?? null) === 'Sabri Website Master Plan V12.3',
    '90-ID register is governed by the actual 13 November master plan');
$assert(($manifest['software_candidate'] ?? null) === '1.4.0-rc.3'
    && ($manifest['schema_version'] ?? null) === '4.0.0',
    'Distinct RC3 package identity retains the approved canonical DB schema');
$assert(($manifest['canonical_table_definitions'] ?? null) === 27,
    'No future-activation table is silently introduced');
$assert(($manifest['source_only'] ?? null) === true
    && ($manifest['live_deployed_verified'] ?? null) === false,
    'Source claim cannot masquerade as deployed Live acceptance');
$assert(($manifest['counts'] ?? null) === [
    'functional'=>34,'constitutional'=>10,'full_journey'=>6,'future'=>40,'total'=>90,
], 'Master plan exactly contains 34 functional, ten CEN, six NJ and forty future entries');

$entries = $manifest['requirements'] ?? null;
$assert(is_array($entries) && count($entries) === 90,
    'Machine-readable source-to-release register contains exactly ninety IDs');

$seen = [];
$kinds = ['functional'=>0,'constitutional'=>0,'full_journey'=>0,'future'=>0];
foreach ($entries as $index => $entry) {
    $id = $entry['id'] ?? '';
    $type = $entry['kind'] ?? '';
    $path = $entry['source_owner'] ?? '';
    $assert(is_string($id) && !isset($seen[$id]), "Requirement ID is unique: {$index}");
    $seen[$id] = true;
    $assert(isset($kinds[$type]), "Requirement kind is canonical: {$id}");
    $kinds[$type]++;

    $assert(is_string($path)
        && preg_match('#^src/[A-Za-z0-9_/]+\.php$#D', $path) === 1
        && is_file($root.'/'.$path),
        "Requirement points to an existing source-owner file: {$id}");

    $fqcn = 'Sabri\\CF03\\'.str_replace('/', '\\', substr($path, 4, -4));
    $assert(class_exists($fqcn) || interface_exists($fqcn),
        "Mapped implementation is autoloadable: {$id}");

    if ($type !== 'future') {
        $assert(($entry['staging_acceptance'] ?? null) === 'unverified'
            && ($entry['live_acceptance'] ?? null) === 'unverified',
            "Pre-production acceptance is honestly unverified: {$id}");
    } else {
        $assert(($entry['activated'] ?? true) === false
            && ($entry['live_acceptance'] ?? '') === 'not_approved',
            "Future capability is not activated: {$id}");
    }
}
$assert($kinds === ['functional'=>34,'constitutional'=>10,'full_journey'=>6,'future'=>40],
    'No functional, constitutional, journey or future requirement is missing');

for ($n=1; $n<=34; $n++) {
    $id = sprintf('CF03-FR-%03d', $n);
    $assert(isset($seen[$id]) && str_contains($trace, $id),
        "Master plan FR has both owner mapping and human trace: {$id}");
}
for ($n=1; $n<=10; $n++) {
    $assert(isset($seen[sprintf('CF03-CEN-%02d', $n)]), 'All ten constitutional rules are present');
}
for ($n=1; $n<=6; $n++) {
    $assert(isset($seen[sprintf('CF03-NJ-%02d', $n)]), 'All six end-to-end journeys are present');
}

$registry = FutureExpansionRegistry::all();
$oldNames = [];
foreach ($previousFuture['features'] ?? [] as $future) {
    $oldNames[$future['id']] = $future['name'];
}
for ($n=1; $n<=40; $n++) {
    $id = sprintf('FX-%02d', $n);
    $matching = array_values(array_filter($entries,
        static fn(array $row): bool => ($row['id'] ?? null) === $id));
    $assert(count($matching) === 1 && isset($registry[$id], $oldNames[$id]),
        "All approved Future40 features are registered: {$id}");
    $item = $matching[0];
    $assert(($item['label'] ?? null) === $oldNames[$id]
        && ($item['activated'] ?? true) === false
        && ($registry[$id]['activated'] ?? true) === false,
        "Future registry, manifest title and explicit OFF state agree: {$id}");
    $assert(($item['conditional_change_control'] ?? null) === in_array($id, ['FX-38','FX-39'], true),
        "Only the separately governed Sharia/Waqf future features require special change control: {$id}");
}

$workflow = (string)file_get_contents($root.'/.github/workflows/ci.yml');
$bootstrap = (string)file_get_contents($root.'/cf-03-payments-billing-donations-financial-operations.php');
$assert(str_contains($workflow, 'tests/run-v123-90-requirement-gates.php'),
    'Exact V12.3 90-item source gate is mandatory in GitHub CI');
$assert(str_contains($bootstrap, 'Version: 1.4.0-rc.3'),
    'Executable WordPress source agrees with manifest candidate');
$assert(str_contains($workflow, 'mariadb_refund:'),
    'Real MariaDB concurrency acceptance is not silently dropped');
$assert(str_contains($workflow, 'tests/run-pre-provider-checkout-rate-gate.php')
    && str_contains($workflow, 'tests/run-outbox-consumer-boundary.php'),
    'Release CI retains atomic abuse and durable event-bus receipt checks');

fwrite(STDOUT, "CF-03 V12.3 ninety-ID release evidence registry: {$checks}/{$checks} assertions passed; staging and Live remain unverified.\n");
