<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use RuntimeException;
use Sabri\CF03\Persistence\CompleteSchema;

final class WordPressSchemaInstaller
{
    /** @return list<string> */
    public static function install(): array
    {
        global $wpdb;
        if(!isset($wpdb)||!is_object($wpdb)||!isset($wpdb->prefix)){throw new RuntimeException('WordPress database connection is unavailable for CF-03 schema installation.');}
        if(!function_exists('dbDelta')){$upgrade=defined('ABSPATH')?ABSPATH.'wp-admin/includes/upgrade.php':'';if($upgrade!==''&&is_file($upgrade)){require_once $upgrade;}}
        if(!function_exists('dbDelta')){throw new RuntimeException('WordPress dbDelta is unavailable for CF-03 schema installation.');}
        if(!method_exists($wpdb,'get_var')||!method_exists($wpdb,'prepare')){throw new RuntimeException('WordPress database verification methods are unavailable.');}
        $tables=CompleteSchema::tables((string)$wpdb->prefix);$applied=[];$missingTables=[];
        foreach($tables as $name=>$sql){
            dbDelta($sql);
            if(preg_match('/^CREATE TABLE\s+([^\s(]+)/i',$sql,$matches)!==1){throw new RuntimeException('CF-03 schema statement does not expose a verifiable table name: '.$name.'.');}
            $table=$matches[1];$query=$wpdb->prepare('SHOW TABLES LIKE %s',$table);$found=$wpdb->get_var($query);
            if(!is_string($found)||$found!==$table){$missingTables[]=$table;continue;}
            $applied[]='cf03-'.CompleteSchema::VERSION.'-'.$name;
        }
        if($missingTables!==[]){throw new RuntimeException('CF-03 schema verification failed for: '.implode(', ',$missingTables).'.');}
        if(count($applied)!==count($tables)){throw new RuntimeException('CF-03 schema installation did not verify every canonical table.');}
        return $applied;
    }
}
