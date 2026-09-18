<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use Sabri\CF03\Infrastructure\WordPressSchemaInstaller;
use Sabri\CF03\Persistence\CompleteSchema;

final class Round27Wpdb
{
    public string $prefix='wp_';
    public function prepare(string $sql,mixed ...$args):string{return $sql;}
    public function get_var(string $sql):mixed{return 'wp_sabri_cf03_migrations';}
    public function get_results(string $sql,string $mode='ARRAY_A'):array
    {
        return [[
            'migration_id'=>'cf03-'.CompleteSchema::VERSION.'-intents',
            'checksum'=>str_repeat('0',64),
            'status'=>'completed',
        ]];
    }
    public function esc_like(string $value):string{return $value;}
}

$method=new ReflectionMethod(WordPressSchemaInstaller::class,'preflightMigrationChecksums');
$method->setAccessible(true);
$failed=false;
try {
    $method->invoke(null,new Round27Wpdb(),CompleteSchema::tables('wp_'));
} catch(Throwable) {
    $failed=true;
}
if(!$failed){
    throw new RuntimeException('Schema installer must reject same-version checksum drift before dbDelta mutation.');
}

$source=(string)file_get_contents(dirname(__DIR__).'/src/Infrastructure/WordPressSchemaInstaller.php');
$pre=strpos($source,'self::preflightMigrationChecksums($wpdb, $tables);');
$delta=strpos($source,'dbDelta($sql);');
if($pre===false||$delta===false||$pre>$delta){
    throw new RuntimeException('Migration checksum preflight must execute before the first dbDelta mutation.');
}

fwrite(STDOUT,"PASS: schema migration checksum identity is verified before any same-version mutation\n");
