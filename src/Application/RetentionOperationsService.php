<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Contracts\RetentionActionExecutor;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class RetentionOperationsService
{
    private const MODES=['archive','anonymize','delete','retain_immutable'];
    private const IMMUTABLE=['ledger_transaction','ledger_entry','audit_event','provider_event','settlement_batch','invoice','refund_record','chargeback_record','finance_period'];

    public function __construct(
        private readonly QueryableFinancialRepository $repository,
        private readonly RetentionActionExecutor $executor
    ) {}

    /** @return array<string,mixed> */
    public function schedule(
        string $recordType,
        string $recordReference,
        string $dataClass,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $expiresAt,
        string $deleteMode
    ): array {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/',$recordType)!==1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/',$recordReference)!==1
            || preg_match('/^[A-Z][0-9A-Z]{0,7}$/',$dataClass)!==1
            || !in_array($deleteMode,self::MODES,true)
        ) {
            throw new InvalidArgumentException('Retention schedule fields are invalid.');
        }
        if ($expiresAt!==null && $expiresAt<=$createdAt) {
            throw new InvalidArgumentException('Retention expiry must follow record creation.');
        }
        if (in_array($recordType,self::IMMUTABLE,true)
            && $deleteMode!=='retain_immutable'
            && $deleteMode!=='archive'
        ) {
            throw new InvariantViolation('Immutable financial evidence cannot be deleted or anonymized by ordinary retention.');
        }
        $record=[
            'record_type'=>$recordType,
            'record_ref'=>$recordReference,
            'data_class'=>$dataClass,
            'created_at'=>$createdAt,
            'expires_at'=>$expiresAt,
            'delete_mode'=>$deleteMode,
            'legal_hold'=>false,
            'legal_hold_ref'=>null,
            'action_state'=>'pending',
            'action_evidence_ref'=>null,
            'actioned_at'=>null,
        ];
        $this->repository->insert('retention_ledger',$recordReference,$record);
        return $record;
    }

    /** @return array<string,mixed> */
    public function placeLegalHold(string $recordReference,string $holdReference):array
    {
        $this->reference($holdReference);
        $updated=$this->repository->updateWhere('retention_ledger',[
            'record_ref'=>$recordReference,
            'legal_hold'=>false,
            'actioned_at'=>null,
            'action_state'=>'pending',
        ],[
            'legal_hold'=>true,
            'legal_hold_ref'=>$holdReference,
        ]);
        if($updated!==1){throw new InvariantViolation('Retention record is missing, already on legal hold, or retention action has begun.');}
        return ['record_ref'=>$recordReference,'legal_hold'=>true,'legal_hold_ref'=>$holdReference];
    }

    /** @return array<string,mixed> */
    public function releaseLegalHold(string $recordReference,string $holdReference):array
    {
        $updated=$this->repository->updateWhere('retention_ledger',[
            'record_ref'=>$recordReference,
            'legal_hold'=>true,
            'legal_hold_ref'=>$holdReference,
            'actioned_at'=>null,
            'action_state'=>'pending',
        ],[
            'legal_hold'=>false,
            'legal_hold_ref'=>null,
        ]);
        if($updated!==1){throw new InvariantViolation('Legal hold release reference does not match or retention action has begun.');}
        return ['record_ref'=>$recordReference,'legal_hold'=>false];
    }

    /** @return array<string,mixed> */
    public function executeDue(string $recordReference,DateTimeImmutable $now):array
    {
        $record=$this->repository->get('retention_ledger',$recordReference);
        if($record===null){throw new InvariantViolation('Retention record was not found.');}
        if((bool)$record['legal_hold']){throw new InvariantViolation('Legal hold blocks retention action.');}
        if($record['actioned_at']!==null || ($record['action_state']??null)==='completed'){
            return [
                'record_ref'=>$recordReference,
                'status'=>'already_actioned',
                'evidence_reference'=>$record['action_evidence_ref']??null,
            ];
        }
        $state=(string)($record['action_state']??'pending');
        if(in_array($state,['executing','uncertain'],true)){
            throw new InvariantViolation('Retention action is in an uncertain/in-progress state and requires explicit reconciliation before retry.');
        }
        if($state!=='pending'){
            throw new InvariantViolation('Retention action state is invalid.');
        }
        if($record['expires_at']===null){return ['record_ref'=>$recordReference,'status'=>'retained'];}
        $expires=$record['expires_at'] instanceof DateTimeImmutable?$record['expires_at']:new DateTimeImmutable((string)$record['expires_at']);
        if($expires>$now){throw new InvariantViolation('Retention action is not due.');}
        $mode=(string)$record['delete_mode'];
        if($mode==='retain_immutable'){
            return ['record_ref'=>$recordReference,'status'=>'retained_immutable'];
        }

        $claimed=$this->repository->updateWhere('retention_ledger',[
            'record_ref'=>$recordReference,
            'legal_hold'=>false,
            'actioned_at'=>null,
            'action_state'=>'pending',
        ],[
            'action_state'=>'executing',
        ]);
        if($claimed!==1){
            throw new InvariantViolation('Retention action could not acquire its single-execution claim.');
        }

        try {
            $evidence=match($mode){
                'archive'=>$this->executor->archive((string)$record['record_type'],$recordReference),
                'anonymize'=>$this->executor->anonymize((string)$record['record_type'],$recordReference),
                'delete'=>$this->executor->delete((string)$record['record_type'],$recordReference),
                default=>throw new InvariantViolation('Unknown retention action mode.'),
            };
        } catch (Throwable $error) {
            $this->repository->updateWhere('retention_ledger',[
                'record_ref'=>$recordReference,
                'action_state'=>'executing',
                'actioned_at'=>null,
            ],[
                'action_state'=>'uncertain',
            ]);
            throw new InvariantViolation(
                'Retention executor failed after the single-execution claim; automatic replay is blocked pending reconciliation.',
                0,
                $error
            );
        }

        if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/',$evidence)!==1){
            $this->repository->updateWhere('retention_ledger',[
                'record_ref'=>$recordReference,
                'action_state'=>'executing',
                'actioned_at'=>null,
            ],[
                'action_state'=>'uncertain',
            ]);
            throw new InvariantViolation('Retention executor returned invalid evidence; automatic replay is blocked pending reconciliation.');
        }

        $updated=$this->repository->updateWhere('retention_ledger',[
            'record_ref'=>$recordReference,
            'action_state'=>'executing',
            'actioned_at'=>null,
        ],[
            'action_state'=>'completed',
            'action_evidence_ref'=>$evidence,
            'actioned_at'=>$now,
        ]);
        if($updated!==1){
            throw new InvariantViolation('Retention action completed externally but evidence could not be committed; automatic replay remains blocked.');
        }
        return [
            'record_ref'=>$recordReference,
            'status'=>$mode,
            'evidence_reference'=>$evidence,
            'actioned_at'=>$now->format(DATE_ATOM),
        ];
    }

    /** @return array<string,mixed> */
    public function reconcileUncertain(
        string $recordReference,
        string $verifiedEvidenceReference,
        DateTimeImmutable $verifiedAt
    ): array {
        $this->reference($verifiedEvidenceReference);
        $updated=$this->repository->updateWhere('retention_ledger',[
            'record_ref'=>$recordReference,
            'legal_hold'=>false,
            'action_state'=>'uncertain',
            'actioned_at'=>null,
        ],[
            'action_state'=>'completed',
            'action_evidence_ref'=>$verifiedEvidenceReference,
            'actioned_at'=>$verifiedAt,
        ]);
        if($updated!==1){
            throw new InvariantViolation('Uncertain retention action could not be reconciled from verified external evidence.');
        }
        return [
            'record_ref'=>$recordReference,
            'status'=>'reconciled_completed',
            'evidence_reference'=>$verifiedEvidenceReference,
            'actioned_at'=>$verifiedAt->format(DATE_ATOM),
        ];
    }

    private function reference(string $value):void
    {
        if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,191}$/',$value)!==1){
            throw new InvalidArgumentException('Retention reference is invalid.');
        }
    }
}
