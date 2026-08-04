<?php

declare(strict_types=1);

namespace Sabri\CF03\Infrastructure;

use Sabri\CF03\Persistence\Schema;

final class WordPressSchemaInstaller
{
    /** @return list<string> */
    public static function install(): array
    {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb) || ! isset($wpdb->prefix)) {
            return [];
        }

        if (! function_exists('dbDelta')) {
            $upgrade = defined('ABSPATH') ? ABSPATH . 'wp-admin/includes/upgrade.php' : '';
            if ($upgrade !== '' && is_file($upgrade)) {
                require_once $upgrade;
            }
        }
        if (! function_exists('dbDelta')) {
            return [];
        }

        $applied = [];
        foreach (Schema::tables((string) $wpdb->prefix) as $name => $sql) {
            dbDelta($sql);
            $applied[] = 'cf03-' . Schema::VERSION . '-' . $name;
        }
        return $applied;
    }
}
