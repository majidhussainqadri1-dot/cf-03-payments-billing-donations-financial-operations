<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Sabri\CF03\Integration\CF05FinancialAggregate;
use Sabri\CF03\Support\InvariantViolation;

$checks=0;
$ok=static function(bool $test,string $msg)use(&$checks):void {
    $checks++;if(!$test){throw new RuntimeException('FAIL: '.$msg);}
};
$reject=static function(callable $test,string $msg)use($ok):void {
    $blocked=false;try{$test();}catch(InvariantViolation){$blocked=true;}
    $ok($blocked,$msg);
};
$hash=str_repeat('a',64);
$payload=[
    'snapshot_id'=>'snapshot.2026-10.usd',
    'as_of'=>'2026-10-21T00:00:00+00:00',
    'last_financial_update_at'=>'2026-10-20T10:00:00+00:00',
    'currency'=>'USD',
    'total_donations_minor'=>120000,
    'current_month_donations_minor'=>20000,
    'current_year_donations_minor'=>90000,
    'total_expenses_minor'=>0,
    'founder_paid_or_withdrawn_minor'=>0,
    'current_balance_minor'=>120000,
    'expense_categories_minor'=>[],
    'source_hash'=>$hash,
    // Injected internal/PII fields must never cross the allowlist.
    'donor_ref'=>'user:PII-1001',
    'customer_email'=>'sensitive@example.test',
    'provider_payment_ref'=>'ref:private:1',
    'ledger_rows'=>[['account'=>'income.donation','donor'=>'secret']],
];
$canonicalSort = static function(mixed $x)use(&$canonicalSort):mixed{
    if(!is_array($x)){return $x;}
    if(!array_is_list($x)){ksort($x,SORT_STRING);}
    foreach($x as $k=>$v){$x[$k]=$canonicalSort($v);}
    return $x;
};
$snapshotHash=hash('sha256',json_encode($canonicalSort($payload),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$row=[
    'snapshot_id'=>'snapshot.2026-10.usd','currency'=>'USD',
    'period_key'=>'2026-10','publication_state'=>'published',
    'snapshot_json'=>$payload,'source_hash'=>$hash,'snapshot_hash'=>$snapshotHash,
];
$dt=CF05FinancialAggregate::fromPublishedSnapshot($row);
$serialized=json_encode($dt,JSON_THROW_ON_ERROR);
$ok($dt['source_snapshot_hash']===$snapshotHash,'Authentic immutable snapshot hash passed to consumer');
$ok($dt['source_owner']==='CF-03' && $dt['consumer_owner']==='CF-05','Finance truth remains CF-03 owned');
$ok($dt['contract_version']===CF05FinancialAggregate::CONTRACT_VERSION,'Stable typed aggregate contract');
$ok($dt['metrics_minor_units']['net_donations_lifetime']===120000,'Approved ledger-derived lifetime aggregate preserved');
$ok($dt['metrics_minor_units']['net_donations_period']===20000,'Approved period aggregate preserved');
$ok($dt['metrics_minor_units']['approved_expenses_lifetime']===0,'Expenses recomputed from typed canonical snapshot (no arbitrary claim)');
$ok($dt['individual_records_included']===false && $dt['financial_decision_authority']===false,
    'Analytics cannot acquire donor records or financial authority');
$ok(!str_contains($serialized,'PII-1001')&&!str_contains($serialized,'sensitive@example.test')
    && !str_contains($serialized,'ledger_rows'),'Arbitrary sensitive and raw ledger properties excluded');
$ok(count($dt['metrics_minor_units'])===5,'Only five allowlisted financial metrics exposed');
$unpublished=$row;$unpublished['publication_state']='draft';
$reject(static fn()=>CF05FinancialAggregate::fromPublishedSnapshot($unpublished),'Unpublished snapshots prohibited');
$conflict=$row;$conflict['source_hash']=str_repeat('c',64);
$reject(static fn()=>CF05FinancialAggregate::fromPublishedSnapshot($conflict),'Mismatched source provenance rejected');
$corrupt=$row;$corrupt['snapshot_json']='raw ledger data';
$reject(static fn()=>CF05FinancialAggregate::fromPublishedSnapshot($corrupt),'Unstructured source denied');
$badPeriod=$row;$badPeriod['period_key']='invalid';
$reject(static fn()=>CF05FinancialAggregate::fromPublishedSnapshot($badPeriod),'Invalid reporting period rejected');
$badId=$row;$badId['snapshot_id']='snapshot.different.usd';
$reject(static fn()=>CF05FinancialAggregate::fromPublishedSnapshot($badId),'Different canonical snapshot ID rejected');
$badCurrency=$row;$badCurrency['currency']='EUR';
$reject(static fn()=>CF05FinancialAggregate::fromPublishedSnapshot($badCurrency),'Conflicting canonical currency rejected');
$badTotals=$row;$badTotals['snapshot_json']['current_month_donations_minor']=999999;
$bad=false;try{CF05FinancialAggregate::fromPublishedSnapshot($badTotals);}catch(Throwable){$bad=true;}
$ok($bad,'Impossible accounting aggregates fail closed');
$ok(!str_contains(file_get_contents(__DIR__.'/../src/Integration/CF05FinancialAggregate.php'),'register_rest_route'),
    'Adapter does not activate, publish or expose a REST route');

fwrite(STDOUT,"CF-03/CF-05 aggregate owner/consumer boundary: {$checks}/{$checks} passed; Live connector not activated.\n");
