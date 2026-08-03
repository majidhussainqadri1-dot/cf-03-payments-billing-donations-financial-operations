<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Financial and audit data must never be purged by ordinary uninstall.
// A future explicit, owner-approved purge workflow must enforce retention,
// legal holds, provider deletion, evidence, and reconciliation requirements.
