<?php

declare(strict_types=1);

namespace Sabri\CF03\Application;

final class RouteCatalogue
{
    /** @return list<array<string,mixed>> */
    public static function definitions(): array
    {
        return [
            ['route'=>'/pricing','owner'=>'CF-03','shell_owner'=>'File 20','visual_owner'=>'File 25','methods'=>['GET'],'authentication'=>'public','cache'=>'public_by_locale_currency_policy_version','index'=>'indexable','status'=>'free_core_and_donation_disclosure_only'],
            ['route'=>'/checkout/{product}','owner'=>'CF-03','shell_owner'=>'File 20','visual_owner'=>'File 25','methods'=>['POST'],'authentication'=>'authenticated','cache'=>'no_store','index'=>'noindex','status'=>'paid_core_checkout_prohibited'],
            ['route'=>'/billing','owner'=>'CF-03','shell_owner'=>'File 20','visual_owner'=>'File 25','methods'=>['GET'],'authentication'=>'authenticated_owner_scope','cache'=>'private_no_store','index'=>'noindex','status'=>'one_time_donation_history_contract_ready'],
            ['route'=>'/donate','owner'=>'CF-03','shell_owner'=>'File 20','visual_owner'=>'File 25','methods'=>['GET','POST'],'authentication'=>'public_read_authenticated_or_guest_intent','cache'=>'public_copy_private_intent','index'=>'indexable_copy_noindex_checkout','status'=>'one_time_only_collection_fail_closed_until_gates'],
            ['route'=>'/transparency/','owner'=>'CF-03','shell_owner'=>'File 20','visual_owner'=>'File 25','methods'=>['GET'],'authentication'=>'public_aggregate_only','cache'=>'public_by_snapshot_version','index'=>'indexable','status'=>'contract_ready_snapshot_required'],
            ['route'=>'/transparency/{snapshot}/download','owner'=>'CF-03','shell_owner'=>'File 20','visual_owner'=>'File 25','methods'=>['GET'],'authentication'=>'public_verified_aggregate_snapshot_only','cache'=>'private_or_short_public_by_snapshot_hash','index'=>'noindex','status'=>'download_contract_ready_live_delivery_fail_closed'],
            ['route'=>'/billing/donations','owner'=>'CF-03','shell_owner'=>'File 20','visual_owner'=>'File 25','methods'=>['GET'],'authentication'=>'authenticated_owner_scope','cache'=>'private_no_store','index'=>'noindex','status'=>'one_time_history_only_recurring_retired'],
            ['route'=>'/billing/invoices/{id}/download','owner'=>'CF-03','shell_owner'=>'File 20','visual_owner'=>'File 25','methods'=>['GET'],'authentication'=>'authenticated_invoice_owner_scope_click_time_revalidation','cache'=>'private_no_store','index'=>'noindex','status'=>'download_contract_ready_live_delivery_fail_closed'],
            ['route'=>'/billing/receipts/{id}/download','owner'=>'CF-03','shell_owner'=>'File 20','visual_owner'=>'File 25','methods'=>['GET'],'authentication'=>'authenticated_receipt_owner_scope_click_time_revalidation','cache'=>'private_no_store','index'=>'noindex','status'=>'download_contract_ready_live_delivery_fail_closed'],
            ['route'=>'/billing/refunds/{id}','owner'=>'CF-03','shell_owner'=>'File 20','visual_owner'=>'File 25','methods'=>['GET','POST'],'authentication'=>'authenticated_owner_or_finance_scope','cache'=>'private_no_store','index'=>'noindex','status'=>'contract_ready'],
            ['route'=>'/admin/finance','owner'=>'CF-03','shell_owner'=>'File 20','visual_owner'=>'File 25','methods'=>['GET','POST'],'authentication'=>'finance_capability_step_up','cache'=>'private_no_store','index'=>'noindex','status'=>'contract_ready_runtime_fail_closed'],
            ['route'=>'/admin/finance/exports/{id}/download','owner'=>'CF-03','shell_owner'=>'File 20','visual_owner'=>'File 25','methods'=>['GET'],'authentication'=>'finance_export_requester_or_explicit_finance_scope_step_up_click_time_revalidation','cache'=>'private_no_store','index'=>'noindex','status'=>'download_contract_ready_live_delivery_fail_closed'],
            ['route'=>'/admin/finance/donation-policy','owner'=>'CF-03','shell_owner'=>'File 20','visual_owner'=>'File 25','methods'=>['GET','POST'],'authentication'=>'finance_policy_capability_dual_control','cache'=>'private_no_store','index'=>'noindex','status'=>'one_time_donation_policy_only_change_control_required'],
            ['route'=>'/api/finance/v1/*','owner'=>'CF-03','shell_owner'=>null,'visual_owner'=>null,'methods'=>['GET','POST'],'authentication'=>'versioned_capability_object_field_purpose','cache'=>'endpoint_specific','index'=>'noindex','status'=>'contract_ready_runtime_fail_closed'],
        ];
    }
}
