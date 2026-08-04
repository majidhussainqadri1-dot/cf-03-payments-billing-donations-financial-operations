<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Domain\FinancialTransparencySnapshot;

final class WordPressTransparencyRepository
{
    public function latestPublished(): ?FinancialTransparencySnapshot
    {
        global $wpdb;
        if(!isset($wpdb)||!is_object($wpdb)||!isset($wpdb->prefix)||!method_exists($wpdb,'prepare')||!method_exists($wpdb,'get_var')){return null;}
        try{
            $table=(string)$wpdb->prefix.'sabri_cf03_transparency_snapshots';
            $sql=$wpdb->prepare("SELECT snapshot_json FROM {$table} WHERE publication_state = %s ORDER BY published_at DESC, id DESC LIMIT 1",'published');
            $json=$wpdb->get_var($sql);
            if(!is_string($json)||$json===''){return null;}
            $record=json_decode($json,true,64,JSON_THROW_ON_ERROR);
            return is_array($record)?FinancialTransparencySnapshot::fromArray($record):null;
        }catch(\Throwable){return null;}
    }
}
