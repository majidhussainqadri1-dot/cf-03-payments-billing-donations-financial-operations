<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\SystemIntegrityService;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;

function round23Service(MemoryFinancialRepository $repo): SystemIntegrityService
{
    return new SystemIntegrityService($repo, new FinancialAuditService($repo));
}

$orphan = new MemoryFinancialRepository();
$orphan->insert('ledger_entries', 'entry.orphan.debit', [
    'transaction_id'=>'txn.missing','account'=>'asset.test','direction'=>'debit',
    'amount_minor'=>100,'currency'=>'USD','source_ref'=>'entry.orphan.debit',
]);
$orphan->insert('ledger_entries', 'entry.orphan.credit', [
    'transaction_id'=>'txn.missing','account'=>'income.test','direction'=>'credit',
    'amount_minor'=>100,'currency'=>'USD','source_ref'=>'entry.orphan.credit',
]);
$failed=false;
try { round23Service($orphan)->ledgerBalance(); } catch (Throwable) { $failed=true; }
if(!$failed){ throw new RuntimeException('Balanced orphan entries must not pass integrity.'); }

$empty = new MemoryFinancialRepository();
$empty->insert('ledger_transactions','txn.empty',[
    'transaction_id'=>'txn.empty','source_type'=>'test','source_ref'=>'source.empty',
    'effective_at'=>new DateTimeImmutable(),'recorded_at'=>new DateTimeImmutable(),
    'actor_ref'=>'system:test','reason'=>'test','period_id'=>'2026-09','reversal_of'=>null,'trace_id'=>'trace.empty',
]);
$failed=false;
try { round23Service($empty)->ledgerBalance(); } catch (Throwable) { $failed=true; }
if(!$failed){ throw new RuntimeException('Ledger transaction without entries must not pass integrity.'); }

$repo = new MemoryFinancialRepository();
for($i=0;$i<501;$i++){
    $repo->insert('reconciliation_exceptions','exception.'.str_pad((string)$i,4,'0',STR_PAD_LEFT),[
        'exception_id'=>'exception.'.str_pad((string)$i,4,'0',STR_PAD_LEFT),
        'batch_id'=>'batch.test','exception_type'=>'test','source_ref'=>'source.'.$i,
        'expected_minor'=>1,'actual_minor'=>0,'currency'=>'USD',
        'material'=>$i===500,'state'=>'open','owner_ref'=>null,'accepted_risk_ref'=>null,
        'resolution_ref'=>null,'created_at'=>new DateTimeImmutable(),'resolved_at'=>null,
    ]);
}
$health=round23Service($repo)->health();
if(($health['open_reconciliation_exceptions']??0)!==501 || ($health['open_material_exceptions']??0)!==1){
    throw new RuntimeException('Integrity health must scan beyond the first 500 open exceptions.');
}

fwrite(STDOUT,"PASS: ledger integrity rejects orphan/empty records and health scans complete exception history\n");
