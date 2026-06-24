<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('lcmt_dev_consent_settings');
delete_option('lcmt_dev_consent_db_version');

$table = $wpdb->prefix . 'lcmt_consent_log';
$wpdb->query("DROP TABLE IF EXISTS {$table}");

$policies = $wpdb->prefix . 'lcmt_consent_policies';
$wpdb->query("DROP TABLE IF EXISTS {$policies}");

$ts = wp_next_scheduled('lcmt_dev_consent_purge');
if ($ts) {
    wp_unschedule_event($ts, 'lcmt_dev_consent_purge');
}
