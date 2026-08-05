<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Contracts\RecurringDonationProvider;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Support\InvariantViolation;

final class DonationManagementService
{
    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly DonationProviderRegistry $providers,
        private readonly RuntimeConfiguration $configuration
    ) {}

    /** @return array<string,mixed> */
    public function view(string $actorReference): array
    {
        $records=$this->repository->find('recurring_consents',['actor_ref'=>$actorReference],100);
        $safe=[];
        foreach($records as $record){$safe[]=array_intersect_key($record,array_flip(['consent_id','product_id','amount_minor','currency','interval_code','next_charge_at','state','captured_at','revoked_at','version']));}
        return ['recurring_donations'=>$safe,'cancellation_must_be_easy'=>true,'automatic_renewal_without_explicit_consent'=>false];
    }

    /** @return array<string,mixed> */
    public function cancel(string $consentId,string $actorReference,string $idempotencyKey,int $expectedVersion,DateTimeImmutable $now): array
    {
        $this->configuration->assertFinancialMutationReady();$this->assertIdempotency($idempotencyKey);
        $record=$this->repository->get('recurring_consents',$consentId);
        if($record===null||(string)($record['actor_ref']??'')!==$actorReference){throw new InvariantViolation('Recurring donation was not found in actor scope.');}
        if((int)($record['version']??0)!==$expectedVersion||($record['state']??null)!=='active'){throw new InvariantViolation('Recurring donation is stale or not cancellable.');}
        $donations=$this->repository->find('donations',['recurring_consent_id'=>$consentId],100);
        $providerRef=null;foreach($donations as $donation){if(is_string($donation['provider_ref']??null)&&$donation['provider_ref']!==''){$providerRef=$donation['provider_ref'];break;}}
        if($providerRef===null){throw new InvariantViolation('Recurring donation provider reference is unavailable.');}
        $provider=$this->providers->get($this->configuration->providerCode());
        if(!$provider instanceof RecurringDonationProvider){throw new InvariantViolation('Configured donation provider does not support recurring cancellation.');}
        $confirmation=$provider->cancelRecurringDonation($providerRef,$idempotencyKey);
        $updated=$this->repository->compareAndSwap('recurring_consents',$consentId,$expectedVersion,static function(array $current)use($now,$confirmation):array{$current['state']='cancelled';$current['revoked_at']=$now;$current['provider_confirmation_ref']=$confirmation;return $current;});
        return ['consent_id'=>$consentId,'state'=>'cancelled','revoked_at'=>$now->format(DATE_ATOM),'provider_confirmation_reference'=>$confirmation,'version'=>$updated['version']??null];
    }

    /** @return array<string,mixed> */
    public function changeAmount(string $consentId,string $actorReference,Money $amount,string $idempotencyKey,int $expectedVersion,DateTimeImmutable $now): array
    {
        $this->configuration->assertFinancialMutationReady();$this->assertIdempotency($idempotencyKey);(new \Sabri\CF03\Domain\PlatformFinancialPolicy())->assertSuggestedOrCustomDonation($amount);
        $record=$this->repository->get('recurring_consents',$consentId);
        if($record===null||(string)($record['actor_ref']??'')!==$actorReference){throw new InvariantViolation('Recurring donation was not found in actor scope.');}
        if((int)($record['version']??0)!==$expectedVersion||($record['state']??null)!=='active'){throw new InvariantViolation('Recurring donation is stale or not changeable.');}
        $donations=$this->repository->find('donations',['recurring_consent_id'=>$consentId],100);$providerRef=null;
        foreach($donations as $donation){if(is_string($donation['provider_ref']??null)&&$donation['provider_ref']!==''){$providerRef=$donation['provider_ref'];break;}}
        if($providerRef===null){throw new InvariantViolation('Recurring donation provider reference is unavailable.');}
        $provider=$this->providers->get($this->configuration->providerCode());
        if(!$provider instanceof RecurringDonationProvider){throw new InvariantViolation('Configured donation provider does not support amount changes.');}
        $confirmation=$provider->changeRecurringDonationAmount($providerRef,$amount,$idempotencyKey);
        $updated=$this->repository->compareAndSwap('recurring_consents',$consentId,$expectedVersion,static function(array $current)use($amount,$confirmation):array{$current['amount_minor']=$amount->minorUnits();$current['currency']=$amount->currency();$current['terms_hash']=hash('sha256','donation.monthly|'.$amount->minorUnits().'|'.$amount->currency());$current['provider_confirmation_ref']=$confirmation;return $current;});
        return ['consent_id'=>$consentId,'state'=>(string)$updated['state'],'amount_minor'=>$amount->minorUnits(),'currency'=>$amount->currency(),'provider_confirmation_reference'=>$confirmation,'changed_at'=>$now->format(DATE_ATOM),'version'=>$updated['version']??null];
    }

    private function assertIdempotency(string $key):void{if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/',$key)!==1){throw new InvalidArgumentException('Recurring donation idempotency key is invalid.');}}
}
