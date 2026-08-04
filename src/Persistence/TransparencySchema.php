<?php

declare(strict_types=1);

namespace Sabri\CF03\Persistence;

final class TransparencySchema
{
    /** @return array<string,string> */
    public static function tables(string $prefix): array
    {
        $charset='DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        return [
            'expenses'=>"CREATE TABLE {$prefix}sabri_cf03_expenses (id bigint unsigned NOT NULL AUTO_INCREMENT, expense_id varchar(128) NOT NULL, occurred_at datetime(6) NOT NULL, amount_minor bigint unsigned NOT NULL, currency char(3) NOT NULL, category varchar(64) NOT NULL, purpose varchar(500) NOT NULL, payee_ref varchar(191) NOT NULL, approval_ref varchar(191) NOT NULL, receipt_status varchar(32) NOT NULL, founder_related tinyint(1) NOT NULL DEFAULT 0, public_disclosure_category varchar(128) NOT NULL, source_transaction_id varchar(128) NULL, record_version bigint unsigned NOT NULL DEFAULT 1, created_at datetime(6) NOT NULL, updated_at datetime(6) NOT NULL, PRIMARY KEY(id), UNIQUE KEY expense_id(expense_id), KEY category_date(category,occurred_at), KEY founder_date(founder_related,occurred_at), KEY source_transaction(source_transaction_id)) {$charset};",
            'transparency_snapshots'=>"CREATE TABLE {$prefix}sabri_cf03_transparency_snapshots (id bigint unsigned NOT NULL AUTO_INCREMENT, snapshot_id varchar(128) NOT NULL, period_key varchar(32) NOT NULL, currency char(3) NOT NULL, snapshot_json longtext NOT NULL, source_hash char(64) NOT NULL, publication_state varchar(32) NOT NULL, published_at datetime(6) NULL, created_at datetime(6) NOT NULL, PRIMARY KEY(id), UNIQUE KEY snapshot_id(snapshot_id), UNIQUE KEY period_key(period_key), KEY publication(publication_state,published_at)) {$charset};",
            'donor_acknowledgments'=>"CREATE TABLE {$prefix}sabri_cf03_donor_acknowledgments (id bigint unsigned NOT NULL AUTO_INCREMENT, acknowledgment_id varchar(128) NOT NULL, donation_id varchar(128) NOT NULL, donor_ref varchar(191) NOT NULL, display_name varchar(191) NOT NULL, consent_hash char(64) NOT NULL, consented_at datetime(6) NOT NULL, revoked_at datetime(6) NULL, state varchar(32) NOT NULL, PRIMARY KEY(id), UNIQUE KEY acknowledgment_id(acknowledgment_id), UNIQUE KEY donation_id(donation_id), KEY donor_state(donor_ref,state)) {$charset};",
        ];
    }
}
