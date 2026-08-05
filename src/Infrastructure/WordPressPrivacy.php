<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Throwable;

final class WordPressPrivacy
{
    /** @param array<string,string> $exporters @return array<string,array<string,mixed>> */
    public static function exporters(array $exporters):array{$exporters['sabri-cf03']=['exporter_friendly_name'=>'Sabri Financial Records','callback'=>[self::class,'export']];return $exporters;}
    /** @param array<string,string> $erasers @return array<string,array<string,mixed>> */
    public static function erasers(array $erasers):array{$erasers['sabri-cf03']=['eraser_friendly_name'=>'Sabri Financial Preferences','callback'=>[self::class,'erase']];return $erasers;}

    /** @return array<string,mixed> */
    public static function export(string $emailAddress,int $page=1):array
    {
        $user=self::userByEmail($emailAddress);if($user===null){return ['data'=>[],'done'=>true];}
        try{$actor='user:'.(int)$user->ID;$records=(new \Sabri\CF03\Application\BillingQueryService(WordPressFinancialRepository::fromWordPress()))->forActor($actor,100);}
        catch(Throwable){return ['data'=>[],'done'=>true];}
        $data=[];foreach(['invoices','donations','subscriptions','refunds','exports'] as $group){foreach($records[$group]??[] as $record){$items=[];foreach($record as $name=>$value){$items[]=['name'=>(string)$name,'value'=>is_scalar($value)||$value===null?(string)$value:json_encode($value)];}$data[]=['group_id'=>'sabri-cf03-'.$group,'group_label'=>'Sabri Financial '.ucfirst($group),'item_id'=>'sabri-cf03-'.hash('sha256',$group.'|'.json_encode($record)),'data'=>$items];}}
        return ['data'=>$data,'done'=>true];
    }

    /** @return array<string,mixed> */
    public static function erase(string $emailAddress,int $page=1):array
    {
        $user=self::userByEmail($emailAddress);if($user===null){return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];}
        $removed=false;foreach(['last_donation_prompt_at','next_donation_prompt_at','donation_prompt_status','donation_prompt_snoozed_until','last_donation_completed_at','recurring_donation_status','donation_frequency_preference'] as $key){if(function_exists('delete_user_meta')){$removed=(bool)delete_user_meta((int)$user->ID,$key)||$removed;}}
        try{$repo=WordPressFinancialRepository::fromWordPress();$actor='user:'.(int)$user->ID;$ack=$repo->find('donor_acknowledgments',['donor_ref'=>$actor],500);foreach($ack as $record){$repo->updateWhere('donor_acknowledgments',['acknowledgment_id'=>$record['acknowledgment_id']],['display_name'=>'Anonymous donor','state'=>'revoked','revoked_at'=>new \DateTimeImmutable('now')]);$removed=true;}}
        catch(Throwable){}
        return ['items_removed'=>$removed,'items_retained'=>true,'messages'=>['Financial ledgers, receipts, settlements, audit evidence and legally required accounting records are retained under the applicable retention policy; optional prompt state and public acknowledgment are removed or revoked.'],'done'=>true];
    }

    private static function userByEmail(string $email):?object{if(!function_exists('get_user_by')){return null;}$user=get_user_by('email',$email);return is_object($user)&&isset($user->ID)?$user:null;}
}
