<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

$settlement=(string)file_get_contents(dirname(__DIR__).'/src/Application/SettlementOperationsService.php');
foreach(['reviewPeriod','closePeriod'] as $method){
    $start=strpos($settlement,'public function '.$method.'(');
    if($start===false){throw new RuntimeException('Missing settlement close method '.$method.'.');}
    $next=strpos($settlement,'public function ',$start+20);
    if($next===false){$next=strpos($settlement,'private function ',$start+20);}
    $body=substr($settlement,$start,$next-$start);
    if(!str_contains($body,'$this->repository->transaction(')||!str_contains($body,'$this->audit->append(')){
        throw new RuntimeException($method.' must commit finance-period state and immutable audit atomically.');
    }
}

$daily=(string)file_get_contents(dirname(__DIR__).'/src/Application/DailyReconciliationService.php');
$needle="'chargeback' => \$this->first('chargebacks', [";
$pos=strpos($daily,$needle);
if($pos===false){
    throw new RuntimeException('Daily reconciliation chargeback mapping is missing.');
}
$fragment=substr($daily,$pos,300);
if(!str_contains($fragment,"'provider' => \$batch->providerCode()")){
    throw new RuntimeException('Daily reconciliation must scope chargeback references to the active provider.');
}

fwrite(STDOUT,"PASS: finance close is audit-atomic and daily chargeback reconciliation is provider-scoped\n");
