<?php

declare(strict_types=1);

/**
 * Fresh CF-03 provider evidence boundary review.
 * All fixtures are synthetic; no gateway or production charge is involved.
 */
require __DIR__.'/bootstrap.php';

use Sabri\CF03\Application\ProviderWebhookVerifier;
use Sabri\CF03\Support\InvariantViolation;

$now = new DateTimeImmutable('2026-10-20T20:00:00+00:00');
$secret = str_repeat('T', 48);
$base = [
    'event_id'=>'evt.valid.001', 'type'=>'payment.settled', 'intent_id'=>'intent.valid.001',
    'amount_minor'=>1200, 'currency'=>'PKR', 'occurred_at'=>'2026-10-20T19:59:30+00:00',
];
$passed=0;$failed=[];
$check = static function(string $name, callable $callback) use (&$passed,&$failed):void {
    try {
        if ($callback() !== true) {throw new RuntimeException('Assertion false');}
        $passed++; echo 'PASS '.$name."\n";
    } catch (Throwable $e) {
        $failed[]=$name.': '.$e->getMessage();
        fwrite(STDERR,'FAIL '.$name.': '.$e->getMessage()."\n");
    }
};
$verify = static function(array $payload, int &$reservations, ?callable $unique = null) use($now,$secret):mixed {
    $resolver = static fn(string $provider,string $version):string=>$secret;
    $reserve = $unique ?? static function(string $provider,string $id) use (&$reservations):bool {
        $reservations++;return true;
    };
    $verifier = new ProviderWebhookVerifier($resolver,$reserve);
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature=hash_hmac('sha256',$now->getTimestamp().'.'.$raw,$secret);
    return $verifier->verify('provider.sandbox','key.v1',$raw,$signature,$now,$now);
};
$deniedWithoutReservation = static function(array $payload) use($verify):bool {
    $reservations=0;
    try {$verify($payload,$reservations);return false;}
    catch(Throwable) {return $reservations===0;}
};
$check('01 invalid event identifier never consumes unique-event reservation',static fn()=> $deniedWithoutReservation(array_replace($base,['event_id'=>'a'])));
$check('02 invalid payment intent identifier never consumes reservation',static fn()=> $deniedWithoutReservation(array_replace($base,['intent_id'=>'i'])));
$check('03 invalid event type identifier never consumes reservation',static fn()=> $deniedWithoutReservation(array_replace($base,['type'=>'x'])));
$check('04 invalid calendar date never consumes reservation',static fn()=> $deniedWithoutReservation(array_replace($base,['occurred_at'=>'2026-02-30T19:59:30+00:00'])));
$check('05 relative timestamp never consumes reservation',static fn()=> $deniedWithoutReservation(array_replace($base,['occurred_at'=>'tomorrow'])));
$check('06 timestamp without UTC offset never consumes reservation',static fn()=> $deniedWithoutReservation(array_replace($base,['occurred_at'=>'2026-10-20T19:59:30'])));
$check('07 event occurrence too far in future never consumes reservation',static fn()=> $deniedWithoutReservation(array_replace($base,['occurred_at'=>'2026-10-20T20:07:00+00:00'])));
$check('08 amount missing never consumes reservation',static function()use($base,$deniedWithoutReservation):bool{$bad=$base;unset($bad['amount_minor']);return $deniedWithoutReservation($bad);});
$check('09 negative amount never consumes reservation',static fn()=> $deniedWithoutReservation(array_replace($base,['amount_minor'=>-1])));
$check('10 valid signed event reserves once and returns trusted evidence',static function()use($base,$verify):bool{
    $reservations=0;$result=$verify($base,$reservations);
    return $reservations===1 && $result->eventIdUnique()===true && $result->providerEventId()==='evt.valid.001';
});
$check('11 duplicate event is denied after one reservation lookup',static function()use($base,$verify):bool{
    $reservations=0;
    try {
        $verify($base,$reservations,static function(string $provider,string $id)use(&$reservations):bool{$reservations++;return false;});
        return false;
    } catch(InvariantViolation) {return $reservations===1;}
});
$check('12 valid UTC Z timestamp is accepted',static function()use($base,$verify):bool{
    $reservations=0;$result=$verify(array_replace($base,['occurred_at'=>'2026-10-20T19:59:30Z']),$reservations);
    return $reservations===1 && $result->eventIdUnique();
});
$check('13 valid signed fractional-seconds timestamp is accepted',static function()use($base,$verify):bool{
    $reservations=0;$result=$verify(array_replace($base,['occurred_at'=>'2026-10-20T19:59:30.123456+00:00']),$reservations);
    return $reservations===1 && $result->eventIdUnique();
});

printf("CF-03 fresh provider boundary: %d/13 passed, %d failures\n",$passed,count($failed));
exit($failed===[]?0:1);
