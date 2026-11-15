<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Sabri\CF03\Infrastructure\WordPressFinancialRepository;
use Sabri\CF03\Support\InvariantViolation;

final class RefundEngineWpdb
{
    public string $prefix = 'wp_';
    public string $engine = 'MyISAM';
    public array $queries = [];
    public int $lockingReads = 0;

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args)!==1) { throw new RuntimeException('Unexpected query bindings'); }
        $replacement="'".str_replace("'","''",(string)$args[0])."'";
        return preg_replace('/%[sd]/',$replacement,$query,1) ?? '';
    }
    public function get_row(string $query, mixed $format=null): ?array
    {
        $this->queries[]=$query;
        return $this->engine==='' ? null : ['Engine'=>$this->engine];
    }
    public function get_results(string $query, mixed $format=null): array
    {
        $this->lockingReads++;
        $this->queries[]=$query;
        return [[
            'id'=>'1','intent_id'=>'intent:engine:1',
            'actor_ref'=>'user:1001','amount_minor'=>'500',
            'currency'=>'USD','state'=>'settled','record_version'=>'1',
        ]];
    }
    public function get_col(string $query): array {return [];}
    public function insert(mixed ...$args): int {return 1;}
    public function update(mixed ...$args): int {return 1;}
    public function delete(mixed ...$args): int {return 1;}
    public function query(string $sql): int { $this->queries[]=$sql; return 1; }
}
$n=0;
$check=static function(bool $yes,string $label)use(&$n):void{
    $n++;if(!$yes){throw new RuntimeException('FAIL: '.$label);}
};
$db=new RefundEngineWpdb();
$repo=new WordPressFinancialRepository($db,'wp_');
$blocked=false;
try{$repo->getForUpdate('intents','intent:engine:1');}catch(InvariantViolation){$blocked=true;}
$check($blocked,'Lock rejected outside an active transaction');
$blocked=false;
try{$repo->transaction(static fn()=> $repo->getForUpdate('intents','intent:engine:1'));}
catch(InvariantViolation){$blocked=true;}
$check($blocked,'MyISAM intent refuses refund reservation');
$check($db->lockingReads===0,'MyISAM engine denied before any locking read');
$check(in_array('ROLLBACK',$db->queries,true),'Rejected engine rolls transaction back');

$db->engine='';
$blocked=false;
try{$repo->transaction(static fn()=> $repo->getForUpdate('intents','intent:engine:1'));}
catch(InvariantViolation){$blocked=true;}
$check($blocked,'Unknown engine also fails closed');

$db->engine='InnoDB';
$locked=$repo->transaction(static fn()=> $repo->getForUpdate('intents','intent:engine:1'));
$check(($locked['intent_id']??null)==='intent:engine:1','InnoDB row locking permits verified intent');
$check(($locked['version']??null)===1,'Canonical record version hydrated from locked row');
$check($db->lockingReads===1,'Exactly one locking SELECT used for InnoDB');
$check(count(array_filter($db->queries,static fn($x)=>str_contains($x,'FOR UPDATE')))===1,'Actual query includes exclusive FOR UPDATE');
$check(count(array_filter($db->queries,static fn($x)=>$x==='COMMIT'))===1,'InnoDB reservation lock completes its transaction');
fwrite(STDOUT,"CF-03 InnoDB engine fail-closed lock boundary: {$n}/{$n} mock-adapter assertions passed (real DB staging proof pending).\n");
