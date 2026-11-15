#!/usr/bin/env bash
set -euo pipefail
umask 077

# READ-ONLY operator-assisted freeze. This does not install, deploy,
# migrate, mutate WordPress options, change gates, or collect payment.
# WP-CLI --skip-plugins/--skip-themes reduces boot effects, but mu-plugins
# and host integrations may still execute; obtain authorized host approval.
# Keep output OUT of webroot and public issue trackers.
# Usage: bash scripts/collect-live-cf03-freeze.sh /absolute/wp/root [plugin-slug]
if [[ $# -lt 1 || $# -gt 2 ]]; then
  echo "Usage: $0 /absolute/wordpress/root [plugin-slug]" >&2
  exit 64
fi
if [[ ! -d "$1" ]]; then
  echo "BLOCKED: WordPress root is unavailable." >&2
  exit 66
fi

WP_ROOT="$(cd -- "$1" && pwd -P)"
PLUGIN_SLUG="cf-03-payments-billing-donations-financial-operations"
if [[ $# -eq 2 ]]; then PLUGIN_SLUG="$2"; fi
if [[ ! "$PLUGIN_SLUG" =~ ^[a-z0-9][a-z0-9-]{2,100}$ ]]; then
  echo "BLOCKED: plugin slug is not canonical." >&2
  exit 64
fi
PLUGIN_DIR="$WP_ROOT/wp-content/plugins/$PLUGIN_SLUG"
ENTRY_FILE="$PLUGIN_DIR/cf-03-payments-billing-donations-financial-operations.php"

echo "=== CF03 EXACT DEPLOYED REALITY FREEZE: OPERATOR-ASSISTED ==="
printf 'Collection UTC: %s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
printf 'Plugin slug: %s\n' "$PLUGIN_SLUG"
echo "Source-independent collection only. Live release acceptance is NOT inferred."
if [[ ! -f "$ENTRY_FILE" ]]; then
  echo "BLOCKED: expected deployed plugin entry point absent."
  echo "Investigate actual installed path without treating GitHub as deployed."
  exit 65
fi

echo "=== EXACT DEPLOYED PACKAGE FILE HASHES (NO FILE CONTENT) ==="
(
  cd -- "$PLUGIN_DIR"
  find . -type f -not -path './.git/*' -print0 |
    LC_ALL=C sort -z |
    xargs -0 -r sha256sum
)
echo "=== PLUGIN ENTRY-POINT DECLARED VERSION (NOT ACTIVE RUNTIME TRUTH) ==="
sed -n '1,36p' "$ENTRY_FILE" |
  grep -E '^\s*\*\s*(Plugin Name|Version|Requires PHP):|SABRI_CF03_(VERSION|SCHEMA_VERSION)' || true

if ! command -v wp >/dev/null 2>&1; then
  echo "WP-CLI UNAVAILABLE: runtime and DB version/migrations remain UNKNOWN."
  exit 0
fi

echo "=== WP-CLI READ-ONLY METADATA (MUST NOT EXPOSE PRIVATE OPTIONS) ==="
wp --path="$WP_ROOT" --skip-plugins --skip-themes --no-color core version ||
  echo "WP core metadata unavailable"
wp --path="$WP_ROOT" --skip-plugins --skip-themes --no-color plugin get "$PLUGIN_SLUG" --fields=name,status,version --format=json ||
  echo "Plugin activation/version metadata unavailable"
echo "=== CURATED, NON-SECRET CF03 OPTIONS ==="
for key in sabri_cf03_version sabri_cf03_schema_version sabri_cf03_runtime_mode sabri_cf03_webhook_enabled sabri_cf03_download_delivery_enabled; do
  printf '%s=' "$key"
  wp --path="$WP_ROOT" --skip-plugins --skip-themes --no-color option get "$key" --format=plaintext 2>/dev/null ||
    echo "UNKNOWN"
done

echo "=== DATABASE TABLE PRESENCE / ENGINES / APPROXIMATE ROWS ==="
wp --path="$WP_ROOT" --skip-plugins --skip-themes --no-color db query \
  "SELECT TABLE_NAME,ENGINE,TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '%sabri_cf03_%' ORDER BY TABLE_NAME" --skip-column-names ||
  echo "DB table or engine evidence unavailable"
echo "=== CF03 COLUMN NAMES/TYPES ONLY — NEVER DONOR/PAYMENT ROW VALUES ==="
wp --path="$WP_ROOT" --skip-plugins --skip-themes --no-color db query \
  "SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '%sabri_cf03_%' ORDER BY TABLE_NAME,ORDINAL_POSITION" --skip-column-names ||
  echo "DB column evidence unavailable"

echo "=== REMAINING READ-ONLY EVIDENCE STILL REQUIRED ==="
echo "Obtain separately authorized, REDACTED migration rows, relevant wp_options,"
echo "runtime errors/logs, approved provider configuration, WAF policy and active"
echo "dependencies. Never print secrets or personally identifiable financial rows."
echo "Compare exact deployed file SHA256 against approved GitHub HEAD/package."
echo "Until checked: DEPLOYMENT PARITY / LIVE DB / MIGRATION = UNVERIFIED."
