<?php
declare(strict_types=1);

/**
 * Source-level negative review of the operator-assisted S0 collection script.
 * This cannot prove shell commands have no effects on a real host; Hostinger
 * and WP-CLI bootstrap approvals remain external.
 */
$script=(string)file_get_contents(__DIR__.'/../scripts/collect-live-cf03-freeze.sh');
$checks=0;
$test=static function(bool $ok,string $label)use(&$checks):void{
    $checks++;
    if(!$ok)throw new RuntimeException('FAIL: '.$label);
};
$test(str_starts_with($script, '#!/usr/bin/env bash') && str_contains($script,'set -euo pipefail'),
    'Read-only operator helper fails closed under shell errors');
$test(str_contains($script,'umask 077'),
    'Potential deployment evidence is not world-readable by default');
$test(str_contains($script, 'sha256sum'),
    'Exact deployed artifact hashes are measured without displaying source');
$test(str_contains($script,'--skip-plugins --skip-themes'),
    'WP-CLI suppresses ordinary plugin and theme bootstrap');
$test(str_contains($script,"information_schema.TABLES") && str_contains($script,"information_schema.COLUMNS"),
    'Read-only DB engine/table/column probes are included');
$test(str_contains($script,'sabri_cf03_schema_version') && str_contains($script,'sabri_cf03_version'),
    'Exact installed schema and runtime source versions are independently probed');
$test(!preg_match('/^[ \t]*wp[ \t]+.*\b(?:option (?:update|delete|add)|plugin (?:install|activate|deactivate|update)|db (?:import|drop|reset)|eval|search-replace)\b/im',$script),
    'No direct mutating WP-CLI command is present');
$test(!preg_match('/^[ \t]*(?:mysql|mariadb)\s+(?:-e|--execute)\b/im',$script),
    'Direct ad-hoc SQL writes cannot bypass reviewed WordPress reads');
$test(!preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+[a-z0-9_]+\s+SET|DELETE\s+FROM|DROP\s+TABLE|ALTER\s+TABLE)\b/i',$script),
    'No SQL mutation statement appears in operator read-only evidence collector');
$test(str_contains($script,'NEVER DONOR/PAYMENT ROW VALUES'),
    'Donor/payment-record exclusion is explicitly documented');
$test(str_contains($script,'DEPLOYMENT PARITY / LIVE DB / MIGRATION = UNVERIFIED'),
    'Collector never claims deployed verification on source evidence alone');

fwrite(STDOUT,"CF-03 operator S0 read-only source contract: {$checks}/{$checks} checks passed (host approval still required).\n");
