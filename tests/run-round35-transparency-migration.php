<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Infrastructure\WordPressSchemaInstaller;

final class Round35Wpdb
{
    public array $updates=[];
    public array $queries=[];
    public array $rows=[];
    public function get_results(string $sql,string $mode='ARRAY_A'):array
    {
        if(str_contains($sql,'SHOW INDEX')) return [['Key_name'=>'period_key']];
        return $this->rows;
    }
    public function update(string $table,array $data,array $where):int|false
    {
        $this->updates[]=[$data,$where];
        return 1;
    }
    public function query(string $sql):int|false{$this->queries[]=$sql;return 1;}
}

$method=new ReflectionMethod(WordPressSchemaInstaller::class,'repairTransparencySnapshots');
$method->setAccessible(true);
$sourceHash=str_repeat('a',64);
$snapshot=['source_hash'=>$sourceHash,'value'=>1];
$json=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

// Existing valid but mismatching integrity evidence must never be overwritten.
$db=new Round35Wpdb();
$db->rows=[['id'=>1,'snapshot_json'=>$json,'source_hash'=>$sourceHash,'snapshot_hash'=>str_repeat('b',64)]];
$failed=false;
try{$method->invoke(null,$db,'wp_sabri_cf03_transparency_snapshots');}catch(Throwable){$failed=true;}
if(!$failed||$db->updates!==[]||$db->queries!==[]){
    throw new RuntimeException('Installer must fail before blessing or structurally mutating a snapshot with conflicting immutable hash evidence.');
}

// A legacy row with no hash may be deterministically backfilled, then legacy index removed.
$db2=new Round35Wpdb();
$db2->rows=[['id'=>2,'snapshot_json'=>$json,'source_hash'=>$sourceHash,'snapshot_hash'=>'']];
$method->invoke(null,$db2,'wp_sabri_cf03_transparency_snapshots');
if(count($db2->updates)!==1
    || !preg_match('/^[a-f0-9]{64}$/',(string)($db2->updates[0][0]['snapshot_hash']??''))
    || count($db2->queries)!==1
){
    throw new RuntimeException('Legacy missing hash must be backfilled exactly once before index retirement.');
}

fwrite(STDOUT,"PASS: transparency migration never rewrites conflicting immutable snapshot integrity evidence\n");
