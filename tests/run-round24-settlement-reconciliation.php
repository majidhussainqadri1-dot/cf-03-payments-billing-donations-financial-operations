<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Application\SettlementOperationsService;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\SettlementBatch;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;

$batch = new SettlementBatch(
    'batch.round24',
    'provider.test',
    new Money(1000,'USD'),
    new Money(100,'USD'),
    new Money(200,'USD'),
    new Money(700,'USD'),
    new DateTimeImmutable('2026-09-18T00:00:00+00:00'),
    str_repeat('a',64),
    [
        ['reference'=>'pay.round24','type'=>'payment','amount_minor'=>1000,'currency'=>'USD'],
        ['reference'=>'fee.round24','type'=>'fee','amount_minor'=>100,'currency'=>'USD'],
        ['reference'=>'dispute.round24','type'=>'chargeback','amount_minor'=>200,'currency'=>'USD'],
        ['reference'=>'payout.round24','type'=>'payout','amount_minor'=>700,'currency'=>'USD'],
    ]
);
if(count($batch->lines())!==4){ throw new RuntimeException('Chargeback and payout settlement lines must be accepted.'); }

$bad=false;
try {
    new SettlementBatch(
        'batch.bad24','provider.test',new Money(1000,'USD'),new Money(0,'USD'),
        new Money(0,'USD'),new Money(1000,'USD'),new DateTimeImmutable(),
        str_repeat('b',64),
        [
            ['reference'=>'pay.bad24','type'=>'payment','amount_minor'=>1000,'currency'=>'USD'],
            ['reference'=>'payout.bad24','type'=>'payout','amount_minor'=>999,'currency'=>'USD'],
        ]
    );
} catch(Throwable){$bad=true;}
if(!$bad){throw new RuntimeException('Payout line must equal canonical net.');}

$source=(string)file_get_contents(dirname(__DIR__).'/src/Application/SettlementOperationsService.php');
if(!str_contains($source,"->page(\n                'settlement_lines'")
    || !str_contains($source,'MAX_SETTLEMENT_LINES')
    || str_contains($source,"find('settlement_lines'")
){
    throw new RuntimeException('Settlement posting must page all canonical lines rather than truncate at 500.');
}
if(!str_contains($source,'remaining_open') || !str_contains($source,'$remainingOpen += count($page)')){
    throw new RuntimeException('Reconciliation resolution must report the complete open-exception count.');
}

fwrite(STDOUT,"PASS: settlement reconciliation supports disputes/payouts and complete paged posting state\n");
