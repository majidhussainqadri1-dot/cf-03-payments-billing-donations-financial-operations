<?php
declare(strict_types=1);

$GLOBALS['cf03_disclosure'] = [];
function get_option(string $name,mixed $default=null): mixed {
    return $GLOBALS['cf03_disclosure'][$name] ?? $default;
}
require __DIR__.'/bootstrap.php';

use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Infrastructure\WordPressDonationDisclosure;

$tests=0;
$check=static function(bool $ok,string $message)use(&$tests):void{
    $tests++; if(!$ok){throw new RuntimeException('FAIL: '.$message);}
};
$provider='provider.approved';
$check(WordPressDonationDisclosure::forProvider($provider)===null,'No approved terms denies collection');
$record=[
    'approved'=>true,'provider_code'=>$provider,
    'policy_version'=>PlatformFinancialPolicy::DECISION_ID,
    'currency'=>'USD','approval_ref'=>'approval.public.v1',
    'provider_name'=>'Approved hosted payment provider',
    'donation_purpose'=>'Institutional sustainability',
    'provider_fee_terms'=>'Any applicable provider fees shown before checkout',
    'tax_terms'=>'Tax treatment as separately reviewed by qualified advisers',
    'refund_terms'=>'Refunds are reviewed under approved published terms',
    'support_terms'=>'Submit disputes to the approved support channel',
    'data_use_terms'=>'Only necessary financial data used for processing and legal accounting',
];
$GLOBALS['cf03_disclosure'][WordPressDonationDisclosure::OPTION]=$record;
$ok=WordPressDonationDisclosure::forProvider($provider);
$check(is_array($ok) && count($ok)===8,'Approved complete provider-specific disclosure accepted');
$check(!isset($ok['approved'])&&!isset($ok['policy_version']),'Approval metadata is not included in public disclosure');
$check(WordPressDonationDisclosure::forProvider('provider.different')===null,'Provider switching invalidates old terms');
$GLOBALS['cf03_disclosure'][WordPressDonationDisclosure::OPTION]['approved']=false;
$check(WordPressDonationDisclosure::forProvider($provider)===null,'Unapproved text cannot unlock checkout');
$GLOBALS['cf03_disclosure'][WordPressDonationDisclosure::OPTION]=$record;
$GLOBALS['cf03_disclosure'][WordPressDonationDisclosure::OPTION]['tax_terms']='';
$check(WordPressDonationDisclosure::forProvider($provider)===null,'Missing tax disclosure fails closed');
$GLOBALS['cf03_disclosure'][WordPressDonationDisclosure::OPTION]=$record;
$GLOBALS['cf03_disclosure'][WordPressDonationDisclosure::OPTION]['provider_fee_terms']=str_repeat('x',801);
$check(WordPressDonationDisclosure::forProvider($provider)===null,'Oversized fee terms fail closed');
$GLOBALS['cf03_disclosure'][WordPressDonationDisclosure::OPTION]=$record;
$GLOBALS['cf03_disclosure'][WordPressDonationDisclosure::OPTION]['data_use_terms']="ok\0secret";
$check(WordPressDonationDisclosure::forProvider($provider)===null,'Control-character data terms fail closed');
$GLOBALS['cf03_disclosure'][WordPressDonationDisclosure::OPTION]=$record;
$GLOBALS['cf03_disclosure'][WordPressDonationDisclosure::OPTION]['policy_version']='retired.v0';
$check(WordPressDonationDisclosure::forProvider($provider)===null,'Superseded policy evidence rejected');
$GLOBALS['cf03_disclosure'][WordPressDonationDisclosure::OPTION]=$record;
$check(WordPressDonationDisclosure::forProvider($provider)!==null,'Approved record can be restored');

$rest=file_get_contents(__DIR__.'/../src/Infrastructure/WordPressRestApi.php');
$ui=file_get_contents(__DIR__.'/../src/Infrastructure/WordPressPublicUi.php');
$check(str_contains($rest,'WordPressDonationDisclosure::forProvider($runtime->providerCode()) !== null'),
    'Public collection status checks disclosure readiness');
$check(str_contains($rest,'WordPressDonationDisclosure::forProvider($runtime->providerCode()) === null'),
    'Server-side financial mutation rejects missing disclosure');
$check(strpos($ui,'WordPressDonationDisclosure::forProvider(')<strpos($ui,'self::assets($collectionEnabled)'),
    'Disclosure readiness checked before localized browser state');
$check(strpos($ui,'WordPressDonationDisclosure::forProvider(')<strpos($ui,"$"."options = ''"),
    'Disclosure readiness precedes interactive amount controls');
$check(str_contains($ui,".'<dd>'.esc_html("),'Approved terms are escaped before being shown');
fwrite(STDOUT,"CF-03 pre-donation disclosure acceptance: {$tests}/{$tests} checks passed.\n");
