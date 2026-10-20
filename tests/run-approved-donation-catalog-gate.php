<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\ApprovedDonationCatalogGate;
use Sabri\CF03\Application\DonationCatalogSeeder;
use Sabri\CF03\Domain\PlatformFinancialPolicy;
use Sabri\CF03\Infrastructure\MemoryFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

$checks = 0;
$passes = 0;
$expect = static function (bool $condition, string $description) use (&$checks, &$passes): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$description);
    }
    $passes++;
};
$rejects = static function (callable $fn, string $description) use ($expect): void {
    $denied = false;
    try {
        $fn();
    } catch (InvariantViolation) {
        $denied = true;
    }
    $expect($denied, $description);
};

$at = new DateTimeImmutable('2026-10-21T00:00:00+05:00');
$repo = new MemoryFinancialRepository();
$gate = new ApprovedDonationCatalogGate($repo);

$rejects(fn () => $gate->assertReady($at), 'Missing donation product denies checkout');
(new DonationCatalogSeeder())->seed($repo, $at);
$gate->assertReady($at);
$expect(true, 'Approved one-time donation permits catalogue preflight');
$repo->updateWhere('products', ['product_id' => 'donation.one_time'], ['lifecycle_state' => 'retired']);
$rejects(fn () => $gate->assertReady($at), 'Retired catalogue denies checkout');
$repo->updateWhere('products', ['product_id' => 'donation.one_time'], ['lifecycle_state' => 'active']);
$repo->updateWhere('products', ['product_id' => 'donation.one_time'], ['policy_version' => 'superseded']);
$rejects(fn () => $gate->assertReady($at), 'Superseded policy denies checkout');
$repo->updateWhere('products', ['product_id' => 'donation.one_time'], ['policy_version' => PlatformFinancialPolicy::DECISION_ID]);
$repo->updateWhere('products', ['product_id' => 'donation.one_time'], ['entitlement_mapping' => 'premium']);
$rejects(fn () => $gate->assertReady($at), 'Entitlement-linked donation denies checkout');
$repo->updateWhere('products', ['product_id' => 'donation.one_time'], ['entitlement_mapping' => null]);
$repo->updateWhere('products', ['product_id' => 'donation.one_time'], ['approved_by' => '']);
$rejects(fn () => $gate->assertReady($at), 'Unapproved donation denies checkout');
$repo->updateWhere('products', ['product_id' => 'donation.one_time'], ['approved_by' => 'founder:governing-plan']);
$repo->updateWhere('products', ['product_id' => 'donation.one_time'], ['effective_at' => $at->modify('+1 day')]);
$rejects(fn () => $gate->assertReady($at), 'Future-effective donation denies checkout');
$repo->updateWhere('products', ['product_id' => 'donation.one_time'], ['effective_at' => $at]);
$gate->assertReady($at);
$expect(true, 'Current approved catalogue accepts checkout');

$source = file_get_contents(__DIR__.'/../src/Application/DonationCheckoutService.php');
$expect(is_string($source)
    && strpos($source, 'new ApprovedDonationCatalogGate($this->repository)') !== false
    && strpos($source, 'ApprovedDonationCatalogGate($this->repository)') < strpos($source, 'createHostedDonationCheckout('),
    'Checkout checks catalogue before provider side effect');

fwrite(STDOUT, "CF-03 approved donation catalogue gate: {$passes}/{$checks} checks passed.\n");
