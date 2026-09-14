<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\RetentionOperationsService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Application\SecureExportService;
use Sabri\CF03\Contracts\RetentionActionExecutor;
use Sabri\CF03\Contracts\SecureArtifactStore;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

final class ReviewSecureArtifactStore implements SecureArtifactStore
{
    /** @var array<string,string> */ public array $objects = [];

    public function put(string $filename, string $mediaType, string $contents, DateTimeImmutable $expiresAt): array
    {
        $reference = 'vault://finance/'.$filename;
        $this->objects[$reference] = $contents;
        return ['object_ref'=>$reference, 'sha256'=>hash('sha256', $contents), 'size_bytes'=>strlen($contents)];
    }

    public function delete(string $objectReference): void
    {
        unset($this->objects[$objectReference]);
    }
}

final class ReviewRetentionExecutor implements RetentionActionExecutor
{
    /** @var list<string> */ public array $actions = [];
    public function archive(string $recordType, string $recordReference): string { $this->actions[]='archive'; return 'evidence.archive.001'; }
    public function anonymize(string $recordType, string $recordReference): string { $this->actions[]='anonymize'; return 'evidence.anonymize.001'; }
    public function delete(string $recordType, string $recordReference): string { $this->actions[]='delete'; return 'evidence.delete.001'; }
}

$tests = [];

$runtime = static function (): RuntimeConfiguration {
    $gates = array_fill_keys([
        'founder_change_control','legal_tax_accounting','pci_scope','provider_selected',
        'independent_security','staging_acceptance','rollback_evidence','file00_contract',
        'file20_file25_contract','file24_assurance','operations_ready','secure_delivery',
    ], true);
    return new RuntimeConfiguration(DonationServiceState::SANDBOX, 'provider.test', $gates, false, true);
};

$tests['R21 stored export specification is tamper evident'] = static function () use ($runtime): void {
    $repo = new MemoryFinancialRepository(true);
    $store = new ReviewSecureArtifactStore();
    $service = new SecureExportService($repo, $store, $runtime(), new FinancialAuditService($repo));
    $at = new DateTimeImmutable('2026-09-14T15:30:00Z');
    $service->request('export.review21.tamper', 'user.finance.001', ['transaction_id'], [], 10, $at->modify('+1 day'), $at);
    $repo->updateWhere('exports', ['job_id'=>'export.review21.tamper'], [
        'specification_json'=>[
            'job_id'=>'export.review21.tamper',
            'fields'=>['transaction_id','invoice_number'],
            'filters'=>[],
            'maximum_rows'=>10,
            'expires_at'=>$at->modify('+1 day')->format(DATE_ATOM),
            'state'=>'queued',
            'record_version'=>1,
        ],
    ]);
    reviewThrows(
        static fn () => $service->process('export.review21.tamper', 'user.operator.001', 1, $at->modify('+1 minute')),
        InvariantViolation::class
    );
    reviewSame([], $store->objects);
};

$tests['R21 export request process grant revoke are audited and artifact is deleted'] = static function () use ($runtime): void {
    $repo = new MemoryFinancialRepository(true);
    $store = new ReviewSecureArtifactStore();
    $audit = new FinancialAuditService($repo);
    $service = new SecureExportService($repo, $store, $runtime(), $audit);
    $at = new DateTimeImmutable('2026-09-14T15:40:00Z');
    $service->request('export.review21.audit', 'user.finance.002', ['transaction_id'], [], 10, $at->modify('+1 day'), $at);
    $service->process('export.review21.audit', 'user.operator.002', 1, $at->modify('+1 minute'));
    $grant = $service->grant('export.review21.audit', 'user.finance.002', false, $at->modify('+2 minutes'), $at->modify('+12 minutes'));
    reviewSame('finance_export', $grant->toPresentationContract()['asset_type']);
    reviewSame(1, count($store->objects));
    $service->revoke('export.review21.audit', 'user.finance.002', false, 3, $at->modify('+3 minutes'));
    reviewSame([], $store->objects);
    reviewSame('revoked', $repo->get('exports', 'export.review21.audit')['state']);
    reviewSame(null, $repo->get('exports', 'export.review21.audit')['encrypted_object_ref']);
    reviewSame(4, count($repo->all('audit')));
    reviewSame(true, $audit->verifyChain());
};

$tests['R22 retention cannot shorten or change canonical policy'] = static function (): void {
    $repo = new MemoryFinancialRepository(true);
    $executor = new ReviewRetentionExecutor();
    $service = new RetentionOperationsService($repo, $executor);
    $created = new DateTimeImmutable('2026-09-14T00:00:00Z');

    reviewThrows(static fn () => $service->schedule(
        'donation', 'donation.retention.001', 'C4', $created, $created->modify('+1 year'), 'anonymize'
    ), InvariantViolation::class);
    reviewThrows(static fn () => $service->schedule(
        'donation', 'donation.retention.001', 'C4', $created, $created->modify('+10 years'), 'delete'
    ), InvariantViolation::class);
    reviewThrows(static fn () => $service->schedule(
        'ledger_transaction', 'txn.retention.001', 'C4', $created, $created->modify('+10 years'), 'anonymize'
    ), InvariantViolation::class);
    reviewThrows(static fn () => $service->schedule(
        'unknown_financial_record', 'unknown.retention.001', 'C4', $created, $created->modify('+10 years'), 'delete'
    ), InvariantViolation::class);
    reviewSame([], $repo->all('retention_ledger'));
    reviewSame([], $executor->actions);
};

$tests['R22 due retention obeys legal hold and persisted policy'] = static function (): void {
    $repo = new MemoryFinancialRepository(true);
    $executor = new ReviewRetentionExecutor();
    $service = new RetentionOperationsService($repo, $executor);
    $created = new DateTimeImmutable('2016-09-14T00:00:00Z');
    $expiry = new DateTimeImmutable('2026-09-14T00:00:00Z');

    $scheduled = $service->schedule('donation', 'donation.retention.002', 'C4', $created, $expiry, 'anonymize');
    reviewSame(false, $scheduled['reused']);
    $service->placeLegalHold('donation.retention.002', 'hold.legal.001');
    reviewThrows(
        static fn () => $service->executeDue('donation.retention.002', $expiry->modify('+1 day')),
        InvariantViolation::class
    );
    reviewSame([], $executor->actions);
    $service->releaseLegalHold('donation.retention.002', 'hold.legal.001');
    $result = $service->executeDue('donation.retention.002', $expiry->modify('+1 day'));
    reviewSame('anonymize', $result['status']);
    reviewSame(['anonymize'], $executor->actions);
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
fwrite(STDOUT, sprintf("%d review tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

function reviewSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}

/** @param class-string<Throwable> $class */
function reviewThrows(callable $callback, string $class): void
{
    try { $callback(); }
    catch (Throwable $error) {
        if ($error instanceof $class) { return; }
        throw new RuntimeException('Expected '.$class.', got '.$error::class.': '.$error->getMessage());
    }
    throw new RuntimeException('Expected '.$class.' to be thrown.');
}
