<?php
declare(strict_types=1);

/**
 * Fresh 40-point source-to-plan review: CF03-FR-001..034 plus
 * six cross-plan hard-stop contracts. The 34 FR checks validate a real
 * autoloadable class and its current traceability entry; not live acceptance.
 */
require __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\FutureExpansionRegistry;
use Sabri\CF03\Application\EvidenceBoundActivationGate;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Domain\DonationPromptPolicy;
use Sabri\CF03\Persistence\CompleteSchema;
use Sabri\CF03\Persistence\RuntimeSchemaExtension;

$root=dirname(__DIR__);
$trace=file_get_contents($root.'/docs/requirements-traceability.md');
if (!is_string($trace)) {fwrite(STDERR,"Traceability missing\n");exit(2);}
$map=[
    1 => 'Domain/FinancialProduct',
    2 => 'Domain/PriceVersion',
    3 => 'Application/DonationAppealCopy',
    4 => 'Domain/CommissionPolicy',
    5 => 'Domain/DonationNeutralityPolicy',
    6 => 'Application/DonationCheckoutService',
    7 => 'Domain/PaymentIntent',
    8 => 'Application/ProviderWebhookVerifier',
    9 => 'Domain/ProviderEventStateMapper',
    10 => 'Application/PaymentConfirmationService',
    11 => 'Application/ProviderRegistry',
    12 => 'Domain/LedgerTransaction',
    13 => 'Application/FinancialDocumentService',
    14 => 'Application/SettlementOperationsService',
    15 => 'Application/DailyReconciliationService',
    16 => 'Application/FinancialAdjustmentService',
    17 => 'Domain/Money',
    18 => 'Application/DonationCheckoutService',
    19 => 'Domain/DonationPromptPolicy',
    20 => 'Application/DonationCheckoutService',
    21 => 'Domain/DonationNeutralityPolicy',
    22 => 'Application/BillingQueryService',
    23 => 'Domain/PlatformFinancialPolicy',
    24 => 'Application/RefundWorkflowService',
    25 => 'Application/RefundWorkflowService',
    26 => 'Domain/ChargebackCase',
    27 => 'Application/FinancialDocumentService',
    28 => 'Domain/FraudReviewCase',
    29 => 'Domain/SeparationOfDutiesPolicy',
    30 => 'Infrastructure/WordPressPublicUi',
    31 => 'Application/SecureExportService',
    32 => 'Domain/FinancePeriod',
    33 => 'Application/IncidentOperationsService',
    34 => 'Application/RestoreReconciliation',
];
$results=[];
$check=static function(string $id,string $label,callable $condition)use(&$results):void {
    try {$good=$condition()===true;} catch(Throwable $error){$good=false;}
    $results[$id]=$good;
    printf("%s %02d/40 %s %s\n",$good?'PASS':'FAIL',count($results),$id,$label);
};
foreach($map as $n=>$path) {
    $id=sprintf('CF03-FR-%03d',$n);
    $check($id,'code owner + requirement trace',static function()use($id,$path,$root,$trace):bool{
        $rows=preg_grep('/^\\| '.preg_quote($id,'/').' [^\\n]*\\|/m',explode("\n",$trace));
        if(count($rows)!==1)return false;
        $row=(string)reset($rows);
        if((strpos($row,'Implemented')===false && strpos($row,'Source implementation')===false)
            || (strpos($row,'pending')===false && strpos($row,'acceptance')===false && strpos($row,'applicable')===false)){
            return false;
        }
        $php=$root.'/src/'.$path.'.php';
        if(!is_file($php))return false;
        $fqcn='Sabri\\CF03\\'.str_replace('/','\\',$path);
        return class_exists($fqcn)||interface_exists($fqcn);
    });
}
$check('CEN-BIZ','one free tier, 0% commission, one-time donations',static function():bool{
    $p=new PlatformFinancialPolicy();
    return $p->allCoreServicesFree()&&$p->structuredEducationFree()&&$p->aiCoreFree()
      &&$p->platformCommissionBasisPoints()===0&&$p->oneTimeDonationOnly()
      &&!$p->recurringDonationAvailable();
});
$check('CEN-DON','seven-day no-repeat prompt minimum',static fn():bool=>
    DonationPromptPolicy::MINIMUM_INTERVAL==='P7D'&&PlatformFinancialPolicy::APPEAL_MINIMUM_DAYS===7);
$check('CEN-SCHEMA','27-table active and no revived recurring schema',static function():bool{
    $t=CompleteSchema::tables('wp_');
    if(count($t)!==27 || CompleteSchema::VERSION!=='4.0.0')return false;
    foreach(RuntimeSchemaExtension::RETIRED_TABLES as $old){if(isset($t[$old]))return false;}
    return true;
});
$check('CEN-FX40','all Future40 remain inert and non-privileging',static function():bool{
    FutureExpansionRegistry::assertIntegrity();
    $list=FutureExpansionRegistry::all();
    if(count($list)!==40)return false;
    foreach($list as $v){
        if(($v['activated']??true)!==false || ($v['donor_privilege_allowed']??true)!==false
          || ($v['recurring_donation_allowed']??true)!==false || ($v['paid_core_allowed']??true)!==false
          || ($v['platform_commission_basis_points']??-1)!==0)return false;
    }
    return true;
});
$check('CEN-RELEASE','runtime and plan registry aligned with manifest',static function()use($root):bool{
    $m=json_decode((string)file_get_contents($root.'/manifests/cf03-release-1.4.0.json'),true,512,JSON_THROW_ON_ERROR);
    $future=json_decode((string)file_get_contents($root.'/manifests/cf03-future-expansion-40.json'),true,512,JSON_THROW_ON_ERROR);
    return ($m['software_version']??'')==='1.4.0-rc.1'
       &&($m['schema_version']??'')==='4.0.0'
       &&($m['future_features_activated']??-1)===0
       &&($m['platform_commission_basis_points']??-1)===0
       &&($future['feature_count']??0)===40
       &&($future['activation']??'')==='fail_closed_by_default';
});
$check('CEN-ACTIVATION','no evidence means no runtime activation',static function():bool{
    $gate=new EvidenceBoundActivationGate(false,static fn():array=>[],null,'1.4.0-rc.1');
    return $gate->evaluate()->approved()===false;
});
if(count($results)!==40){fwrite(STDERR,"Wrong check count\n");exit(2);}
$failed=array_keys(array_filter($results,static fn(bool $passed):bool=>!$passed));
printf("CF-03 current-plan coding review: %d/40 passed; %d failed. Staging and live unverified.\n",40-count($failed),count($failed));
exit($failed===[]?0:1);
