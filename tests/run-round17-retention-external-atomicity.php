<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\RetentionOperationsService;
use Sabri\CF03\Contracts\RetentionActionExecutor;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;

final class Round17Executor implements RetentionActionExecutor
{
    public int $calls=0;
    public function archive(string $recordType,string $recordReference):string{$this->calls++;return 'evidence.round17';}
    public function anonymize(string $recordType,string $recordReference):string{$this->calls++;return 'evidence.round17';}
    public function delete(string $recordType,string $recordReference):string{$this->calls++;return 'evidence.round17';}
}

$repo=new MemoryFinancialRepository();
$executor=new Round17Executor();
$service=new RetentionOperationsService($repo,$executor);
$created=new DateTimeImmutable('2026-09-01T00:00:00+00:00');
$due=new DateTimeImmutable('2026-09-10T00:00:00+00:00');
$service->schedule('temporary_export','retention.round17','F1',$created,$created->modify('+1 day'),'delete');

$first=$service->executeDue('retention.round17',$due);
$second=$service->executeDue('retention.round17',$due->modify('+1 hour'));
if($executor->calls!==1){
    throw new RuntimeException('Completed external retention action must never replay automatically.');
}
if(($first['actioned_at']??null)!==($second['actioned_at']??null)){
    throw new RuntimeException('Retention retry must expose the canonical original action timestamp for idempotent audit recovery.');
}

$source=(string)file_get_contents(dirname(__DIR__).'/src/Infrastructure/WordPressFinanceAdminApi.php');
$start=strpos($source,'public static function executeRetention(');
$next=strpos($source,'public static function declareIncident(',$start);
$body=substr($source,$start,$next-$start);
if(str_contains($body,'auditedRetention(')||str_contains($body,'$repo->transaction(')){
    throw new RuntimeException('External retention execution must not be enclosed in a rollbackable database transaction.');
}
if(!str_contains($body,'retention_action_executed')||!str_contains($body,'action_evidence_ref')){
    throw new RuntimeException('Retention execution must still append immutable audit from durable action evidence.');
}

fwrite(STDOUT,"PASS: destructive retention execution survives audit failures without replaying external action\n");
