<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\RuntimeConfiguration;
use Sabri\CF03\Domain\DonationServiceState;
use Sabri\CF03\Support\InvariantViolation;

$tests=[];

$baseGates=[
    'founder_change_control'=>true,
    'legal_tax_accounting'=>true,
    'pci_scope'=>true,
    'provider_selected'=>true,
    'independent_security'=>true,
    'staging_acceptance'=>true,
    'rollback_evidence'=>true,
    'file00_contract'=>true,
    'file20_file25_contract'=>true,
    'file24_assurance'=>true,
    'operations_ready'=>true,
    'secure_delivery'=>true,
];

$tests['secure download delivery rejects preparing runtime even if delivery flags are manually true']=static function()use($baseGates):void{
    $runtime=new RuntimeConfiguration(
        DonationServiceState::PREPARING,
        'provider.test',
        $baseGates,
        false,
        true
    );
    expectInvariant8(static fn()=>$runtime->assertDownloadDeliveryReady());
};

$tests['secure download delivery rejects incomplete financial activation evidence']=static function()use($baseGates):void{
    $gates=$baseGates;
    $gates['independent_security']=false;
    $runtime=new RuntimeConfiguration(
        DonationServiceState::SANDBOX,
        'provider.test',
        $gates,
        false,
        true
    );
    expectInvariant8(static fn()=>$runtime->assertDownloadDeliveryReady());
};

$tests['sandbox secure delivery is permitted only after the full activation envelope passes']=static function()use($baseGates):void{
    $runtime=new RuntimeConfiguration(
        DonationServiceState::SANDBOX,
        'provider.test',
        $baseGates,
        false,
        true
    );
    $runtime->assertDownloadDeliveryReady();
};

$tests['live secure delivery additionally requires founder live approval']=static function()use($baseGates):void{
    $runtime=new RuntimeConfiguration(
        DonationServiceState::LIVE,
        'provider.test',
        $baseGates,
        false,
        true
    );
    expectInvariant8(static fn()=>$runtime->assertDownloadDeliveryReady());

    $gates=$baseGates;
    $gates['founder_live_approval']=true;
    $approved=new RuntimeConfiguration(
        DonationServiceState::LIVE,
        'provider.test',
        $gates,
        false,
        true
    );
    $approved->assertDownloadDeliveryReady();
};

$failures=0;
foreach($tests as$name=>$test){
    try{$test();fwrite(STDOUT,"PASS: {$name}\n");}
    catch(Throwable$error){$failures++;fwrite(STDERR,"FAIL: {$name}: {$error->getMessage()}\n");}
}
fwrite(STDOUT,count($tests)." tests, {$failures} failures\n");
exit($failures===0?0:1);

function expectInvariant8(callable$operation):void{
    try{$operation();}
    catch(InvariantViolation){return;}
    throw new RuntimeException('Expected InvariantViolation.');
}
