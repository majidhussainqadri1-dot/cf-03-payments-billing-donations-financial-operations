<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabri\CF03\Application\CanonicalSettlementImportService;
use Sabri\CF03\Application\CatalogDisclosureService;
use Sabri\CF03\Application\ExpenseTransparencyService;
use Sabri\CF03\Application\FinancialAdjustmentService;
use Sabri\CF03\Application\FinancialAuditService;
use Sabri\CF03\Application\FinancialControlRequestService;
use Sabri\CF03\Application\IncidentOperationsService;
use Sabri\CF03\Application\RetentionOperationsService;
use Sabri\CF03\Application\RiskOperationsService;
use Sabri\CF03\Application\SecureExportService;
use Sabri\CF03\Application\SettlementOperationsService;
use Sabri\CF03\Application\SystemIntegrityService;
use Sabri\CF03\Contracts\QueryableFinancialRepository;
use Sabri\CF03\Domain\AuditEnvelope;
use Sabri\CF03\Domain\AuditOutcome;
use Sabri\CF03\Domain\ChargebackCase;
use Sabri\CF03\Domain\DonationExpense;
use Sabri\CF03\Domain\FraudReviewCase;
use Sabri\CF03\Domain\Money;
use Sabri\CF03\Domain\SettlementBatch;
use Sabri\CF03\Support\InvariantViolation;
use Throwable;

final class WordPressFinanceAdminApi
{
    public const NAMESPACE = 'sabri-finance/v1';

    public static function register(): void
    {
        if (!function_exists('register_rest_route')) { return; }
        $routes = [
            ['/catalog','GET','catalog','public','catalog_read'],
            ['/exports','POST','requestExport','exports','finance_export_request'],
            ['/exports/(?P<id>[A-Za-z0-9._:-]+)/(?:grant|download)','POST','grantExport','exports','finance_export_grant'],
            ['/exports/(?P<id>[A-Za-z0-9._:-]+)/revoke','POST','revokeExport','exports','finance_export_revoke'],
            ['/donor-acknowledgments','POST','acknowledgeDonor','authenticated','donor_acknowledgment'],
            ['/donor-acknowledgments/(?P<id>[A-Za-z0-9._:-]+)/revoke','POST','revokeAcknowledgment','authenticated','donor_acknowledgment_revoke'],
            ['/fraud-reviews/(?P<id>[A-Za-z0-9._:-]+)/appeal','POST','appealFraud','authenticated','fraud_appeal'],
            ['/admin/settlements','POST','importSettlement','settlements','settlement_import'],
            ['/admin/settlements/(?P<id>[A-Za-z0-9._:-]+)/post','POST','postSettlement','settlements','settlement_post'],
            ['/admin/reconciliation/(?P<id>[A-Za-z0-9._:-]+)','POST','resolveReconciliation','reconciliation','reconciliation_resolution'],
            ['/admin/periods/(?P<id>[0-9]{4}-[0-9]{2})/review','POST','reviewPeriod','close','finance_period_review'],
            ['/admin/periods/(?P<id>[0-9]{4}-[0-9]{2})/close','POST','closePeriod','close','finance_period_close'],
            ['/admin/periods/(?P<id>[0-9]{4}-[0-9]{2})/reopen-request','POST','requestPeriodReopen','reconciliation','finance_period_reopen_request'],
            ['/admin/periods/(?P<id>[0-9]{4}-[0-9]{2})/reopen','POST','reopenPeriod','close','finance_period_reopen_approve'],
            ['/admin/expenses','POST','recordExpense','expenses','expense_record'],
            ['/admin/transparency','POST','publishTransparency','transparency','transparency_publish'],
            ['/admin/exports/(?P<id>[A-Za-z0-9._:-]+)/process','POST','processExport','exports','finance_export_process'],
            ['/admin/adjustments','POST','requestAdjustment','adjustments','adjustment_request'],
            ['/admin/adjustments/(?P<id>[A-Za-z0-9._:-]+)/decision','POST','decideAdjustment','adjustments','adjustment_decision'],
            ['/admin/adjustments/(?P<id>[A-Za-z0-9._:-]+)/execute','POST','executeAdjustment','adjustments','adjustment_execute'],
            ['/admin/fraud-reviews','POST','openFraud','risk','fraud_review_open'],
            ['/admin/fraud-reviews/(?P<id>[A-Za-z0-9._:-]+)/decision','POST','decideFraud','risk','fraud_review_decision'],
            ['/admin/fraud-reviews/(?P<id>[A-Za-z0-9._:-]+)/close','POST','closeFraud','risk','fraud_review_close'],
            ['/admin/chargebacks','POST','openChargeback','risk','chargeback_open'],
            ['/admin/chargebacks/(?P<id>[A-Za-z0-9._:-]+)/evidence-required','POST','requireChargebackEvidence','risk','chargeback_evidence_required'],
            ['/admin/chargebacks/(?P<id>[A-Za-z0-9._:-]+)/evidence','POST','submitChargebackEvidence','risk','chargeback_evidence_submit'],
            ['/admin/chargebacks/(?P<id>[A-Za-z0-9._:-]+)/accepted','POST','acceptChargebackEvidence','risk','chargeback_evidence_accept'],
            ['/admin/chargebacks/(?P<id>[A-Za-z0-9._:-]+)/outcome','POST','chargebackOutcome','risk','chargeback_outcome'],
            ['/admin/chargebacks/(?P<id>[A-Za-z0-9._:-]+)/ledger','POST','adjustChargeback','risk','chargeback_ledger'],
            ['/admin/chargebacks/(?P<id>[A-Za-z0-9._:-]+)/close','POST','closeChargeback','risk','chargeback_close'],
            ['/admin/retention','POST','scheduleRetention','retention','retention_schedule'],
            ['/admin/retention/(?P<id>[A-Za-z0-9._:-]+)/hold','POST','placeHold','retention','retention_hold'],
            ['/admin/retention/(?P<id>[A-Za-z0-9._:-]+)/release','POST','releaseHold','retention','retention_release'],
            ['/admin/retention/(?P<id>[A-Za-z0-9._:-]+)/execute','POST','executeRetention','retention','retention_execute'],
            ['/admin/retention/(?P<id>[A-Za-z0-9._:-]+)/reconcile','POST','reconcileRetention','retention','retention_reconcile'],
            ['/admin/incidents','POST','declareIncident','incidents','incident_declare'],
            ['/admin/incidents/(?P<id>[A-Za-z0-9._:-]+)/recover-request','POST','requestIncidentRecovery','incidents','incident_recovery_request'],
            ['/admin/incidents/(?P<id>[A-Za-z0-9._:-]+)/recover','POST','recoverIncident','incidents','incident_recovery_approve'],
            ['/admin/incidents/status','GET','incidentStatus','audit','incident_status'],
            ['/admin/integrity','GET','integrity','audit','integrity_read'],
        ];
        foreach ($routes as [$route,$methods,$callback,$permission,$purpose]) {
            register_rest_route(self::NAMESPACE,$route,[
                'methods'=>$methods,
                'callback'=>[self::class,$callback],
                'permission_callback'=>self::permission($permission,$purpose),
            ]);
        }
    }

    private static function permission(string $permission,string $purpose): callable|string
    {
        return match($permission){
            'public'=>'__return_true',
            'authenticated'=>[WordPressRestApi::class,'authenticated'],
            'audit'=>static fn():bool=>self::cap('sabri_view_finance_audit'),
            'settlements'=>static fn():bool=>WordPressSensitiveActionGuard::can('sabri_import_settlements',$purpose),
            'reconciliation'=>static fn():bool=>WordPressSensitiveActionGuard::can('sabri_reconcile_finance',$purpose),
            'close'=>static fn():bool=>WordPressSensitiveActionGuard::can('sabri_close_finance',$purpose),
            'expenses'=>static fn():bool=>WordPressSensitiveActionGuard::can('sabri_record_expenses',$purpose),
            'transparency'=>static fn():bool=>WordPressSensitiveActionGuard::can('sabri_publish_financial_transparency',$purpose),
            'exports'=>static fn():bool=>WordPressSensitiveActionGuard::can('sabri_manage_finance_exports',$purpose),
            'adjustments'=>static fn():bool=>WordPressSensitiveActionGuard::can('sabri_manage_finance_adjustments',$purpose),
            'risk'=>static fn():bool=>WordPressSensitiveActionGuard::can('sabri_manage_finance_risk',$purpose),
            'retention'=>static fn():bool=>WordPressSensitiveActionGuard::can('sabri_manage_finance_retention',$purpose),
            'incidents'=>static fn():bool=>WordPressSensitiveActionGuard::can('sabri_manage_finance_incidents',$purpose),
            default=>static fn():bool=>false,
        };
    }

    public static function catalog(mixed $request=null):mixed{return self::handle(static function():array{$r=self::repo();return(new CatalogDisclosureService($r,new FinancialAuditService($r)))->publicCatalog(new DateTimeImmutable('now'));});}
    public static function requestExport(mixed $request=null):mixed{return self::handle(static fn():array=>self::exports()->request((string)self::param($request,'job_id'),self::actor(),self::stringList(self::param($request,'fields')),self::arrayValue(self::param($request,'filters'),'filters'),self::positive(self::param($request,'maximum_rows')),self::date(self::param($request,'expires_at')),new DateTimeImmutable('now')));}
    public static function processExport(mixed $request=null):mixed{return self::handle(static fn():array=>self::exports()->process((string)self::param($request,'id'),self::actor(),self::positive(self::param($request,'expected_version')),new DateTimeImmutable('now')));}
    public static function grantExport(mixed $request=null):mixed{return self::handle(static function()use($request):array{$g=self::exports()->grant((string)self::param($request,'id'),self::actor(),true,new DateTimeImmutable('now'),self::date(self::param($request,'expires_at')));return $g->toPresentationContract();});}
    public static function revokeExport(mixed $request=null):mixed{return self::handle(static fn():array=>self::exports()->revoke((string)self::param($request,'id'),self::actor(),true,self::positive(self::param($request,'expected_version')),new DateTimeImmutable('now')));}
    public static function acknowledgeDonor(mixed $request=null):mixed{return self::handle(static fn():array=>self::transparency()->consentToAcknowledgment((string)self::param($request,'acknowledgment_id'),(string)self::param($request,'donation_id'),self::actor(),(string)self::param($request,'display_name'),(string)self::param($request,'consent_text'),new DateTimeImmutable('now')));}
    public static function revokeAcknowledgment(mixed $request=null):mixed{return self::handle(static fn():array=>self::transparency()->revokeAcknowledgment((string)self::param($request,'id'),self::actor(),new DateTimeImmutable('now')));}

    public static function importSettlement(mixed $request=null):mixed
    {
        return self::handle(static function()use($request):array{
            $currency=(string)self::param($request,'currency');
            $batch=new SettlementBatch((string)self::param($request,'batch_id'),(string)self::param($request,'provider'),new Money(self::nonNegative(self::param($request,'gross_minor')),$currency),new Money(self::nonNegative(self::param($request,'fee_minor')),$currency),new Money(self::nonNegative(self::param($request,'refund_minor')),$currency),new Money(self::nonNegative(self::param($request,'net_minor')),$currency),self::date(self::param($request,'settled_at')),(string)self::param($request,'source_hash'),self::arrayValue(self::param($request,'lines'),'lines'));
            $repo=self::repo();$runtime=WordPressRuntimeConfiguration::load();$audit=new FinancialAuditService($repo);
            return(new CanonicalSettlementImportService($repo,$runtime,new SettlementOperationsService($repo,$runtime,$audit)))->import($batch,self::integerMap(self::param($request,'materiality_by_currency')),self::actor(),new DateTimeImmutable('now'));
        });
    }
    public static function postSettlement(mixed $request=null):mixed{return self::handle(static fn():array=>self::settlements()->postResolvedBatch((string)self::param($request,'id'),self::actor(),new DateTimeImmutable('now')));}
    public static function resolveReconciliation(mixed $request=null):mixed
    {
        return self::handle(static function()use($request):array{
            $repo=self::repo();$at=new DateTimeImmutable('now');$actor=self::actor();$id=(string)self::param($request,'id');$audit=new FinancialAuditService($repo);
            return $repo->transaction(function()use($repo,$request,$at,$actor,$id,$audit):array{
                $result=(new SettlementOperationsService($repo,WordPressRuntimeConfiguration::load(),$audit))->resolveException($id,$actor,(string)self::param($request,'resolution_reference'),self::boolean(self::param($request,'accepted_risk')),$at);
                self::appendAudit($audit,'reconciliation_exception_resolved','reconciliation_exception',$id,'provider_reconciliation',$actor,$at,['accepted_risk'=>(bool)$result['accepted_risk'],'resolution_reference'=>(string)$result['resolution_reference']]);
                return $result;
            });
        });
    }
    public static function reviewPeriod(mixed $request=null):mixed{return self::handle(static fn():array=>self::settlements()->reviewPeriod((string)self::param($request,'id'),self::actor(),new DateTimeImmutable('now')));}
    public static function closePeriod(mixed $request=null):mixed
    {
        return self::handle(static function()use($request):array{$id=(string)self::param($request,'id');$period=self::repo()->get('finance_periods',$id);if($period===null||!is_string($period['reviewed_by']??null)){throw new InvariantViolation('Finance period has no persisted independent review.');}return self::settlements()->closePeriod($id,(string)$period['reviewed_by'],self::actor(),new DateTimeImmutable('now'));});
    }
    public static function requestPeriodReopen(mixed $request=null):mixed{return self::handle(static fn():array=>self::controls()->requestPeriodReopen((string)self::param($request,'id'),self::actor(),(string)self::param($request,'reason_reference'),new DateTimeImmutable('now')));}
    public static function reopenPeriod(mixed $request=null):mixed{return self::handle(static fn():array=>self::controls()->approvePeriodReopen((string)self::param($request,'id'),self::actor(),new DateTimeImmutable('now')));}

    public static function recordExpense(mixed $request=null):mixed
    {
        return self::handle(static function()use($request):array{$expense=new DonationExpense((string)self::param($request,'expense_id'),self::date(self::param($request,'occurred_at')),self::money($request),(string)self::param($request,'category'),(string)self::param($request,'purpose'),(string)self::param($request,'payee_reference'),(string)self::param($request,'approval_reference'),(string)self::param($request,'receipt_status'),self::boolean(self::param($request,'founder_related')),(string)self::param($request,'public_disclosure_category'));$source=self::param($request,'source_transaction_id');return self::transparency()->recordExpense($expense,self::actor(),new DateTimeImmutable('now'),is_string($source)&&$source!==''?$source:null);});
    }
    public static function publishTransparency(mixed $request=null):mixed{return self::handle(static fn():array=>self::transparency()->buildAndPublishSnapshot((string)self::param($request,'period_key'),(string)self::param($request,'currency'),self::actor(),new DateTimeImmutable('now')));}
    public static function requestAdjustment(mixed $request=null):mixed{return self::handle(static fn():array=>self::adjustments()->request((string)self::param($request,'adjustment_id'),(string)self::param($request,'source_transaction_id'),self::money($request),(string)self::param($request,'debit_account'),(string)self::param($request,'credit_account'),(string)self::param($request,'reason_code'),(string)self::param($request,'evidence_sha256'),self::actor(),new DateTimeImmutable('now')));}
    public static function decideAdjustment(mixed $request=null):mixed{return self::handle(static fn():array=>self::adjustments()->decide((string)self::param($request,'id'),self::boolean(self::param($request,'approve')),self::actor(),self::positive(self::param($request,'expected_version')),new DateTimeImmutable('now')));}
    public static function executeAdjustment(mixed $request=null):mixed{return self::handle(static fn():array=>self::adjustments()->execute((string)self::param($request,'id'),self::actor(),self::positive(self::param($request,'expected_version')),new DateTimeImmutable('now')));}

    public static function openFraud(mixed $request=null):mixed
    {
        return self::handle(static function()use($request):array{
            $repo=self::repo();$at=new DateTimeImmutable('now');$actor=self::actor();$id=(string)self::param($request,'review_id');
            $audit=new FinancialAuditService($repo);
            return $repo->transaction(function()use($repo,$request,$at,$actor,$id,$audit):array{
                $risk=new RiskOperationsService($repo,WordPressRuntimeConfiguration::load());
                $result=$risk->openFraudReview(new FraudReviewCase($id,(string)self::param($request,'subject_reference'),self::arrayValue(self::param($request,'signals'),'signals'),$at,self::date(self::param($request,'hold_until'))),$at);
                self::appendAudit($audit,'fraud_review_opened','fraud_review',$id,'fraud_manual_review',$actor,$at,['state'=>$result['state']??'open']);
                return $result;
            });
        });
    }
    public static function decideFraud(mixed $request=null):mixed
    {
        return self::handle(static function()use($request):array{
            $repo=self::repo();$at=new DateTimeImmutable('now');$actor=self::actor();$id=(string)self::param($request,'id');$audit=new FinancialAuditService($repo);
            return $repo->transaction(function()use($repo,$request,$at,$actor,$id,$audit):array{
                $result=(new RiskOperationsService($repo,WordPressRuntimeConfiguration::load()))->decideFraudReview($id,self::boolean(self::param($request,'approved')),$actor,(string)self::param($request,'reason'),$at,self::positive(self::param($request,'expected_version')));
                self::appendAudit($audit,'fraud_review_decided','fraud_review',$id,'fraud_manual_review',$actor,$at,['state'=>$result['state']??'unknown']);
                return $result;
            });
        });
    }
    public static function appealFraud(mixed $request=null):mixed
    {
        return self::handle(static function()use($request):array{
            $repo=self::repo();$at=new DateTimeImmutable('now');$actor=self::actor();$id=(string)self::param($request,'id');$audit=new FinancialAuditService($repo);
            return $repo->transaction(function()use($repo,$request,$at,$actor,$id,$audit):array{
                $result=(new RiskOperationsService($repo,WordPressRuntimeConfiguration::load()))->appealFraudReview($id,$actor,(string)self::param($request,'reason'),$at,self::positive(self::param($request,'expected_version')));
                self::appendAudit($audit,'fraud_review_appealed','fraud_review',$id,'fraud_due_process',$actor,$at,['state'=>$result['state']??'appealed']);
                return $result;
            });
        });
    }
    public static function closeFraud(mixed $request=null):mixed{return self::auditedRisk($request,'fraud_review_closed','fraud_review','closeFraudReview');}

    public static function openChargeback(mixed $request=null):mixed
    {
        return self::handle(static function()use($request):array{
            $repo=self::repo();$at=new DateTimeImmutable('now');$actor=self::actor();$id=(string)self::param($request,'case_id');$audit=new FinancialAuditService($repo);
            return $repo->transaction(function()use($repo,$request,$at,$actor,$id,$audit):array{
                $case=new ChargebackCase($id,(string)self::param($request,'provider'),(string)self::param($request,'provider_case_reference'),(string)self::param($request,'intent_id'),self::money($request),(string)self::param($request,'reason_code'),$at,self::date(self::param($request,'response_deadline')));
                $result=(new RiskOperationsService($repo,WordPressRuntimeConfiguration::load()))->openChargeback($case,$at);
                self::appendAudit($audit,'chargeback_opened','chargeback',$id,'provider_dispute',$actor,$at,['state'=>$result['state']??'notified']);
                return $result;
            });
        });
    }
    public static function requireChargebackEvidence(mixed $request=null):mixed{return self::auditedRisk($request,'chargeback_evidence_required','chargeback','requireChargebackEvidence');}
    public static function submitChargebackEvidence(mixed $request=null):mixed
    {
        return self::handle(static function()use($request):array{
            $repo=self::repo();$at=new DateTimeImmutable('now');$actor=self::actor();$id=(string)self::param($request,'id');$audit=new FinancialAuditService($repo);
            return $repo->transaction(function()use($repo,$request,$at,$actor,$id,$audit):array{
                $result=(new RiskOperationsService($repo,WordPressRuntimeConfiguration::load()))->submitChargebackEvidence($id,(string)self::param($request,'evidence_sha256'),$at,self::positive(self::param($request,'expected_version')));
                self::appendAudit($audit,'chargeback_evidence_submitted','chargeback',$id,'provider_dispute',$actor,$at,['state'=>$result['state']??'submitted']);
                return $result;
            });
        });
    }
    public static function acceptChargebackEvidence(mixed $request=null):mixed{return self::auditedRisk($request,'chargeback_evidence_accepted','chargeback','acceptChargebackEvidence');}
    public static function chargebackOutcome(mixed $request=null):mixed
    {
        return self::handle(static function()use($request):array{
            $repo=self::repo();$at=new DateTimeImmutable('now');$actor=self::actor();$id=(string)self::param($request,'id');$audit=new FinancialAuditService($repo);
            return $repo->transaction(function()use($repo,$request,$at,$actor,$id,$audit):array{
                $result=(new RiskOperationsService($repo,WordPressRuntimeConfiguration::load()))->recordChargebackOutcome($id,self::boolean(self::param($request,'won')),new Money(self::nonNegative(self::param($request,'provider_fee_minor')),(string)self::param($request,'currency')),$at,self::positive(self::param($request,'expected_version')));
                self::appendAudit($audit,'chargeback_outcome_recorded','chargeback',$id,'provider_dispute',$actor,$at,['state'=>$result['state']??'unknown']);
                return $result;
            });
        });
    }
    public static function adjustChargeback(mixed $request=null):mixed
    {
        return self::handle(static function()use($request):array{
            $repo=self::repo();$at=new DateTimeImmutable('now');$actor=self::actor();$id=(string)self::param($request,'id');$audit=new FinancialAuditService($repo);
            return $repo->transaction(function()use($repo,$request,$at,$actor,$id,$audit):array{
                $result=(new RiskOperationsService($repo,WordPressRuntimeConfiguration::load()))->adjustChargebackLedger($id,$actor,$at,self::positive(self::param($request,'expected_version')));
                self::appendAudit($audit,'chargeback_ledger_adjusted','chargeback',$id,'immutable_ledger_correction',$actor,$at,['transaction_id'=>$result['transaction_id']??'']);
                return $result;
            });
        });
    }
    public static function closeChargeback(mixed $request=null):mixed{return self::auditedRisk($request,'chargeback_closed','chargeback','closeChargeback');}

    public static function scheduleRetention(mixed $request=null):mixed
    {
        return self::handle(static function()use($request):array{$expires=self::param($request,'expires_at');$id=(string)self::param($request,'record_reference');return self::auditedRetention('retention_scheduled',$id,static fn(RetentionOperationsService $s):array=>$s->schedule((string)self::param($request,'record_type'),$id,(string)self::param($request,'data_class'),self::date(self::param($request,'created_at')),is_string($expires)&&$expires!==''?self::date($expires):null,(string)self::param($request,'delete_mode')));});
    }
    public static function placeHold(mixed $request=null):mixed{return self::handle(static fn():array=>self::auditedRetention('retention_legal_hold_placed',(string)self::param($request,'id'),static fn(RetentionOperationsService $s):array=>$s->placeLegalHold((string)self::param($request,'id'),(string)self::param($request,'hold_reference'))));}
    public static function releaseHold(mixed $request=null):mixed{return self::handle(static fn():array=>self::auditedRetention('retention_legal_hold_released',(string)self::param($request,'id'),static fn(RetentionOperationsService $s):array=>$s->releaseLegalHold((string)self::param($request,'id'),(string)self::param($request,'hold_reference'))));}
    public static function reconcileRetention(mixed $request=null):mixed
    {
        return self::handle(static fn():array=>self::auditedRetention(
            'retention_action_reconciled',
            (string)self::param($request,'id'),
            static fn(RetentionOperationsService $s):array=>$s->reconcileUncertain(
                (string)self::param($request,'id'),
                (string)self::param($request,'evidence_reference'),
                new DateTimeImmutable('now')
            )
        ));
    }

    public static function executeRetention(mixed $request=null):mixed
    {
        return self::handle(static function()use($request):array{
            $repo=self::repo();$audit=new FinancialAuditService($repo);$actor=self::actor();$id=(string)self::param($request,'id');$now=new DateTimeImmutable('now');
            // External archive/anonymize/delete is deliberately outside a DB
            // transaction. Once the durable single-execution claim is committed,
            // a later audit failure must not roll the claim back and replay an
            // irreversible external action.
            $result=(new RetentionOperationsService($repo,WordPressRetentionActionExecutorFactory::make()))->executeDue($id,$now);
            $record=$repo->get('retention_ledger',$id);
            if($record===null){throw new InvariantViolation('Retention evidence disappeared after execution.');}
            $actioned=$record['actioned_at']??null;
            $auditAt=$actioned instanceof DateTimeImmutable
                ? $actioned
                : (is_string($actioned)&&$actioned!==''?new DateTimeImmutable($actioned):$now);
            self::appendAudit(
                $audit,
                'retention_action_executed',
                'retention_record',
                $id,
                'retention_legal_hold',
                $actor,
                $auditAt,
                [
                    'action_state'=>(string)($record['action_state']??'pending'),
                    'delete_mode'=>(string)($record['delete_mode']??''),
                    'evidence_reference'=>(string)($record['action_evidence_ref']??''),
                ]
            );
            return $result;
        });
    }

    public static function declareIncident(mixed $request=null):mixed{return self::handle(static fn():array=>self::incidents()->declare((string)self::param($request,'incident_id'),self::nonNegative(self::param($request,'severity')),(string)self::param($request,'reason_code'),self::actor(),new DateTimeImmutable('now'),self::boolean(self::param($request,'kill_checkout'),true),self::boolean(self::param($request,'kill_refunds'),true),self::boolean(self::param($request,'kill_webhooks'),true)));}
    public static function requestIncidentRecovery(mixed $request=null):mixed{return self::handle(static fn():array=>self::controls()->requestIncidentRecovery((string)self::param($request,'id'),self::actor(),(string)self::param($request,'resolution_evidence_reference'),new DateTimeImmutable('now'),self::boolean(self::param($request,'enable_checkout')),self::boolean(self::param($request,'enable_refunds')),self::boolean(self::param($request,'enable_webhooks'))));}
    public static function recoverIncident(mixed $request=null):mixed{return self::handle(static fn():array=>self::controls()->approveIncidentRecovery((string)self::param($request,'id'),self::actor(),(string)self::param($request,'resolution_evidence_reference'),new DateTimeImmutable('now'),self::boolean(self::param($request,'enable_checkout')),self::boolean(self::param($request,'enable_refunds')),self::boolean(self::param($request,'enable_webhooks'))));}
    public static function incidentStatus(mixed $request=null):mixed{return self::handle(static fn():array=>self::incidents()->status());}
    public static function integrity(mixed $request=null):mixed{return self::handle(static fn():array=>self::integrityService()->health());}

    private static function auditedRisk(mixed $request,string $action,string $objectType,string $method):mixed
    {
        return self::handle(static function()use($request,$action,$objectType,$method):array{
            $repo=self::repo();$at=new DateTimeImmutable('now');$actor=self::actor();$id=(string)self::param($request,'id');$version=self::positive(self::param($request,'expected_version'));$audit=new FinancialAuditService($repo);
            return $repo->transaction(function()use($repo,$at,$actor,$id,$version,$action,$objectType,$method,$audit):array{
                $risk=new RiskOperationsService($repo,WordPressRuntimeConfiguration::load());
                $result=$risk->{$method}($id,$at,$version);
                self::appendAudit($audit,$action,$objectType,$id,'provider_dispute',$actor,$at,['state'=>$result['state']??'unknown']);
                return $result;
            });
        });
    }

    private static function auditedRetention(string $action,string $id,callable $operation):array
    {
        $repo=self::repo();$audit=new FinancialAuditService($repo);$actor=self::actor();$at=new DateTimeImmutable('now');
        return $repo->transaction(function()use($repo,$audit,$actor,$at,$action,$id,$operation):array{$result=$operation(new RetentionOperationsService($repo,WordPressRetentionActionExecutorFactory::make()));self::appendAudit($audit,$action,'retention_record',$id,'retention_legal_hold',$actor,$at,['status'=>$result['status']??($result['legal_hold']??'updated')]);return $result;});
    }

    private static function appendAudit(FinancialAuditService $audit,string $action,string $objectType,string $id,string $purpose,string $actor,DateTimeImmutable $at,array $metadata=[]):void
    {
        $audit->append(new AuditEnvelope('audit:admin:'.substr(hash('sha256',$action.'|'.$id.'|'.$actor.'|'.$at->format(DATE_ATOM)),0,32),$actor,$action,$objectType,$id,$purpose,AuditOutcome::SUCCEEDED,$at,'trace:admin:'.substr(hash('sha256',$objectType.'|'.$id),0,24),$metadata));
    }

    private static function repo():WordPressFinancialRepository{return WordPressFinancialRepository::fromWordPress();}
    private static function settlements():SettlementOperationsService{$r=self::repo();return new SettlementOperationsService($r,WordPressRuntimeConfiguration::load(),new FinancialAuditService($r));}
    private static function transparency():ExpenseTransparencyService{$r=self::repo();return new ExpenseTransparencyService($r,new FinancialAuditService($r));}
    private static function exports():SecureExportService{$r=self::repo();return new SecureExportService($r,WordPressSecureArtifactStoreFactory::make(),WordPressRuntimeConfiguration::load(),new FinancialAuditService($r));}
    private static function adjustments():FinancialAdjustmentService{$r=self::repo();return new FinancialAdjustmentService($r,WordPressRuntimeConfiguration::load(),new FinancialAuditService($r));}
    private static function incidents(?QueryableFinancialRepository $r=null):IncidentOperationsService{$r??=self::repo();return new IncidentOperationsService(new WordPressIncidentStateStore(),new FinancialAuditService($r),WordPressRuntimeConfiguration::load());}
    private static function controls():FinancialControlRequestService{$r=self::repo();$audit=new FinancialAuditService($r);$store=new WordPressIncidentStateStore();$incidents=new IncidentOperationsService($store,$audit,WordPressRuntimeConfiguration::load());return new FinancialControlRequestService($r,$audit,$store,$incidents);}
    private static function integrityService():SystemIntegrityService{$r=self::repo();return new SystemIntegrityService($r,new FinancialAuditService($r),WordPressRuntimeConfiguration::backupSnapshot());}
    private static function cap(string $capability):bool{return function_exists('current_user_can')&&current_user_can($capability);}
    private static function actor():string{$id=function_exists('get_current_user_id')?(int)get_current_user_id():0;if($id<1){throw new InvariantViolation('Authenticated actor identity is unavailable.');}return 'user:'.$id;}
    private static function param(mixed $request,string $name):mixed{return is_object($request)&&method_exists($request,'get_param')?$request->get_param($name):null;}
    private static function arrayValue(mixed $value,string $name):array{if(!is_array($value)){throw new InvalidArgumentException($name.' must be an array.');}return $value;}
    private static function stringList(mixed $value):array{if(!is_array($value)){throw new InvalidArgumentException('Expected a string list.');}$r=[];foreach($value as$item){if(!is_string($item)){throw new InvalidArgumentException('Expected a string list.');}$r[]=$item;}return$r;}
    private static function integerMap(mixed $value):array{if(!is_array($value)){throw new InvalidArgumentException('Expected an integer map.');}$r=[];foreach($value as$key=>$item){if(!is_string($key)||!is_int($item)||$item<0){throw new InvalidArgumentException('Expected a non-negative integer map.');}$r[$key]=$item;}return$r;}
    private static function date(mixed $value):DateTimeImmutable{if(!is_string($value)||$value===''){throw new InvalidArgumentException('A date-time is required.');}return new DateTimeImmutable($value);}
    private static function positive(mixed $value):int{if(is_int($value)&&$value>0){return$value;}if(is_string($value)&&preg_match('/^[1-9][0-9]{0,18}$/',$value)===1){$parsed=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(is_int($parsed)){return$parsed;}}throw new InvalidArgumentException('A positive integer is required.');}
    private static function nonNegative(mixed $value):int{if(is_int($value)&&$value>=0){return$value;}if(is_string($value)&&preg_match('/^[0-9]{1,18}$/',$value)===1){$parsed=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if(is_int($parsed)){return$parsed;}}throw new InvalidArgumentException('A non-negative integer is required.');}
    private static function money(mixed $request):Money{return new Money(self::positive(self::param($request,'amount_minor')),(string)self::param($request,'currency'));}
    private static function boolean(mixed $value,bool $default=false):bool{if($value===null){return$default;}return match(true){$value===true,$value===1,$value==='1',$value==='true'=>true,$value===false,$value===0,$value==='0',$value==='false'=>false,default=>throw new InvalidArgumentException('Boolean value is invalid.'),};}
    private static function handle(callable $operation):mixed{try{return$operation();}catch(Throwable$error){$status=$error instanceof InvalidArgumentException?422:($error instanceof InvariantViolation?409:500);$message=$status===500?'The financial administration operation failed safely.':$error->getMessage();if(class_exists('WP_Error')){return new \WP_Error('sabri_cf03_admin_'.($status===422?'invalid':($status===409?'conflict':'error')),$message,['status'=>$status]);}return['code'=>'sabri_cf03_admin_error','message'=>$message,'status'=>$status];}}
}
