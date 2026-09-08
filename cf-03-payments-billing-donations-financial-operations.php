<?php
/**
 * Plugin Name: CF-03 Payments, Billing, Donations and Financial Operations
 * Plugin URI: https://sabrihomeopathy.com/
 * Description: Founder-owned voluntary one-time donations, financial transparency, secure receipts, ledger, refunds, reconciliation and a fail-closed Future Expansion Pack of 40 financial-governance capabilities for the Sabri Social Homeopathy Platform.
 * Version: 1.4.0-rc.1
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Dr. Allamah Majid Hussain Sabri
 * License: GPL-2.0-or-later
 * Text Domain: sabri-cf03-finance
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SABRI_CF03_VERSION', '1.4.0-rc.1');
define('SABRI_CF03_SCHEMA_VERSION', '4.0.0');
define('SABRI_CF03_FUTURE_EXPANSION_ID', 'CF03-FUTURE40-2026-09-08');
define('SABRI_CF03_FILE', __FILE__);
define('SABRI_CF03_DIR', plugin_dir_path(__FILE__));

require_once SABRI_CF03_DIR.'src/Autoloader.php';
\Sabri\CF03\Autoloader::register(SABRI_CF03_DIR.'src');

register_activation_hook(SABRI_CF03_FILE, [\Sabri\CF03\Plugin::class, 'activate']);
register_deactivation_hook(SABRI_CF03_FILE, [\Sabri\CF03\Plugin::class, 'deactivate']);
add_action('plugins_loaded', [\Sabri\CF03\Plugin::class, 'boot']);
