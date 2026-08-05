<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use RuntimeException;
use Sabri\CF03\Contracts\IncidentStateStore;

final class WordPressIncidentStateStore implements IncidentStateStore
{
    public const OPTION='sabri_cf03_incident_state';

    public function get():array
    {
        if(!function_exists('get_option')){return self::normal();}
        $state=get_option(self::OPTION,self::normal());
        return is_array($state)?array_replace(self::normal(),$state):self::normal();
    }

    public function save(array $state):void
    {
        if(!function_exists('update_option')){throw new RuntimeException('WordPress incident state storage is unavailable.');}
        update_option(self::OPTION,$state,false);
    }

    /** @return array<string,mixed> */
    public static function normal():array{return ['state'=>'normal','incident_id'=>null,'severity'=>null,'reason_code'=>null,'checkout_enabled'=>false,'refunds_enabled'=>false,'webhooks_enabled'=>false,'declared_at'=>null,'declared_by'=>null,'recovered_at'=>null,'recovered_by'=>null,'resolution_evidence_ref'=>null,'record_version'=>1];}
}
