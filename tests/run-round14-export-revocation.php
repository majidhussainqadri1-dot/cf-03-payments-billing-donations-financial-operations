<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Application\SecureExportService;
use Sabri\CF03\Contracts\SecureArtifactStore;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;

final class Round14FlakyStore implements SecureArtifactStore
{
    public int $deleteAttempts = 0;
    public function put(string $filename, string $mediaType, string $contents, DateTimeImmutable $expiresAt): array
    {
        return ['object_ref'=>'vault://finance/export.csv','sha256'=>hash('sha256',$contents),'size_bytes'=>strlen($contents)];
    }
    public function delete(string $objectReference): void
    {
        $this->deleteAttempts++;
        if ($this->deleteAttempts === 1) {
            throw new RuntimeException('simulated artifact-store outage');
        }
    }
}

$repo = new MemoryFinancialRepository();
$store = new Round14FlakyStore();
$audit = new FinancialAuditService($repo);
$service = new SecureExportService($repo, $store, RuntimeConfiguration::preparing(), $audit);
$now = new DateTimeImmutable('2026-09-18T05:00:00+00:00');

$repo->insert('exports', 'export.round14', [
    'job_id'=>'export.round14',
    'requester_ref'=>'user:14',
    'specification_hash'=>str_repeat('a',64),
    'specification_json'=>[
        'job_id'=>'export.round14',
        'fields'=>['transaction_id'],
        'filters'=>[],
        'maximum_rows'=>10,
        'expires_at'=>$now->modify('+1 day')->format(DATE_ATOM),
        'state'=>'ready',
        'record_version'=>1,
    ],
    'maximum_rows'=>10,
    'state'=>'ready',
    'encrypted_object_ref'=>'vault://finance/export.csv',
    'manifest_hash'=>str_repeat('b',64),
    'expires_at'=>$now->modify('+1 day'),
    'record_version'=>1,
    'created_at'=>$now->modify('-1 hour'),
    'updated_at'=>$now->modify('-1 hour'),
]);

$failed = false;
try {
    $service->revoke('export.round14', 'user:14', false, 1, $now);
} catch (RuntimeException) {
    $failed = true;
}
if (!$failed) {
    throw new RuntimeException('First revoke must expose the simulated external deletion failure.');
}
$afterFailure = $repo->get('exports', 'export.round14');
if (($afterFailure['state'] ?? null) !== 'revoked'
    || ($afterFailure['encrypted_object_ref'] ?? null) !== 'vault://finance/export.csv'
) {
    throw new RuntimeException('Revocation must fail closed while retaining the artifact reference for retry.');
}
if (count($repo->all('audit')) !== 1) {
    throw new RuntimeException('Revocation state and immutable audit evidence must commit exactly once before external cleanup.');
}

$result = $service->revoke('export.round14', 'user:14', false, 1, $now->modify('+1 minute'));
if (($result['state'] ?? null) !== 'revoked' || ($result['reused'] ?? false) !== true) {
    throw new RuntimeException('Retry with the original caller version must resume an already-revoked cleanup.');
}
$final = $repo->get('exports', 'export.round14');
if (!array_key_exists('encrypted_object_ref', $final) || $final['encrypted_object_ref'] !== null || $store->deleteAttempts !== 2) {
    throw new RuntimeException('Retry must finish physical artifact deletion and clear its durable reference.');
}
if (count($repo->all('audit')) !== 1) {
    throw new RuntimeException('Cleanup retry must not manufacture a second revocation audit event.');
}

fwrite(STDOUT, "PASS: secure export revocation survives artifact-store failure and retries exactly once\n");
