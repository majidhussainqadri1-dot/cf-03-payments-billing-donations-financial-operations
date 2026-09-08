<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Contracts\RetentionActionExecutor;
use Sabri\CF03\Support\InvariantViolation;

final class RetentionOperationsService
{
    private const MODES=['archive','anonymize','delete','retain_immutable'];
    private const IMMUTABLE=['ledger_transaction','ledger_entry','audit_event','provider_event','settlement_batch','invoice','refund_record','chargeback_record','finance_period'];
    public function __construct(private readonly QueryableFinancialRepository $repository,private readonly RetentionActionExecutor $executor){}
    /** @return array<string,mixed> */
    public function schedule(string $recordType,string $recordReference,string $dataClass,DateTimeImmutable $createdAt,?DateTimeImmutable $expiresAt,string $deleteMode):array{if(preg_match('/^[a-z][a-z0-9_]{2,63}$/',$recordType)!==1||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/',$recordReference)!==1||preg_match('/^[A-Z][0-9A-Z]{0,7}$/',$dataClass)!==1||!in_array($deleteMode,self::MODES,true)){throw new InvalidArgumentException('Retention schedule fields are invalid.');}if($expiresAt!==null&&$expiresAt<=$createdAt){throw new InvalidArgumentException('Retention expiry must follow record creation.');}if(in_array($recordType,self::IMMUTABLE,true)&&$deleteMode!=='retain_immutable'&&$deleteMode!=='archive'){throw new InvariantViolation('Immutable financial evidence cannot be deleted or anonymized by ordinary retention.');}$record=['record_type'=>$recordType,'record_ref'=>$recordReference,'data_class'=>$dataClass,'created_at'=>$createdAt,'expires_at'=>$expiresAt,'delete_mode'=>$deleteMode,'legal_hold'=>false,'legal_hold_ref'=>null,'actioned_at'=>null];$this->repository->insert('retention_ledger',$recordReference,$record);return $record;}
    /** @return array<string,mixed> */
    public function placeLegalHold(string $recordReference,string $holdReference):array{$this->reference($holdReference);$updated=$this->repository->updateWhere('retention_ledger',['record_ref'=>$recordReference,'legal_hold'=>false],['legal_hold'=>true,'legal_hold_ref'=>$holdReference]);if($updated!==1){throw new InvariantViolation('Retention record is missing or already on legal hold.');}return ['record_ref'=>$recordReference,'legal_hold'=>true,'legal_hold_ref'=>$holdReference];}
    /** @return array<string,mixed> */
    public function releaseLegalHold(string $recordReference,string $holdReference):array{$updated=$this->repository->updateWhere('retention_ledger',['record_ref'=>$recordReference,'legal_hold'=>true,'legal_hold_ref'=>$holdReference],['legal_hold'=>false,'legal_hold_ref'=>null]);if($updated!==1){throw new InvariantViolation('Legal hold release reference does not match.');}return ['record_ref'=>$recordReference,'legal_hold'=>false];}
    /** @return array<string,mixed> */
    public function executeDue(string $recordReference,DateTimeImmutable $now):array{$record=$this->repository->get('retention_ledger',$recordReference);if($record===null){throw new InvariantViolation('Retention record was not found.');}if((bool)$record['legal_hold']){throw new InvariantViolation('Legal hold blocks retention action.');}if($record['actioned_at']!==null){return ['record_ref'=>$recordReference,'status'=>'already_actioned'];}if($record['expires_at']===null){return ['record_ref'=>$recordReference,'status'=>'retained'];}$expires=$record['expires_at'] instanceof DateTimeImmutable?$record['expires_at']:new DateTimeImmutable((string)$record['expires_at']);if($expires>$now){throw new InvariantViolation('Retention action is not due.');}$mode=(string)$record['delete_mode'];if($mode==='retain_immutable'){return ['record_ref'=>$recordReference,'status'=>'retained_immutable'];}$evidence=match($mode){'archive'=>$this->executor->archive((string)$record['record_type'],$recordReference),'anonymize'=>$this->executor->anonymize((string)$record['record_type'],$recordReference),'delete'=>$this->executor->delete((string)$record['record_type'],$recordReference),default=>throw new InvariantViolation('Unknown retention action mode.')};if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/',$evidence)!==1){throw new InvariantViolation('Retention executor returned invalid evidence.');}$updated=$this->repository->updateWhere('retention_ledger',['record_ref'=>$recordReference,'actioned_at'=>null],['actioned_at'=>$now]);if($updated!==1){throw new InvariantViolation('Retention action evidence could not be committed.');}return ['record_ref'=>$recordReference,'status'=>$mode,'evidence_reference'=>$evidence,'actioned_at'=>$now->format(DATE_ATOM)];}
    private function reference(string $value):void{if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/',$value)!==1){throw new InvalidArgumentException('Retention reference is invalid.');}}
}
