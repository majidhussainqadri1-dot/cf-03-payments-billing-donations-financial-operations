<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

$incident=(string)file_get_contents(dirname(__DIR__).'/src/Application/IncidentOperationsService.php');
if(!str_contains($incident,"return \$current + ['reused' => true]")
    || !str_contains($incident,"'audit:incident:'")
    || !str_contains($incident,"new DateTimeImmutable((string)\$current['declared_at'])")
){
    throw new RuntimeException('Contained incident exact replay must repair immutable declaration audit evidence.');
}

foreach(['CanonicalSettlementImportService.php','DailyReconciliationService.php'] as $file){
    $source=(string)file_get_contents(dirname(__DIR__).'/src/Application/'.$file);
    if(!str_contains($source,"'refund' => \$this->refundForProvider(\$reference, \$batch->providerCode())")
        || !str_contains($source,"private function refundForProvider(")
        || !str_contains($source,"(\$intent['provider'] ?? null) === \$providerCode")
    ){
        throw new RuntimeException($file.' must scope refund settlement matching through the refund canonical intent provider.');
    }
}

$checkout=(string)file_get_contents(dirname(__DIR__).'/src/Application/DonationCheckoutService.php');
foreach([
    "'explicit_one_time_consent' => true",
    "'purpose_code' => 'institutional_sustainability_and_homeopathy_advancement'",
    "'no_privilege_policy' => PlatformFinancialPolicy::DECISION_ID",
] as $needle){
    if(!str_contains($checkout,$needle)){
        throw new RuntimeException('Donation request hash is missing durable one-time-consent evidence: '.$needle);
    }
}

fwrite(STDOUT,"PASS: final adversarial review closes incident-audit, provider-refund and one-time-consent evidence gaps\n");
