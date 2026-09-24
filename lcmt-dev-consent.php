<?php

/**
 * Plugin Name: LCMT Consent
 * Plugin URI:  https://github.com/Brukols/lcmt-dev-consent
 * Description: Lightweight, performance-focused cookie consent banner. Loads banner JS/CSS only when consent is pending; injects accepted services server-side.
 * Version:     1.5.1
 * Author:      Amaury Lecomte
 * Author URI:  https://amaurylecomte.com
 * Text Domain: lcmt-dev-consent
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LCMT_DEV_CONSENT_VERSION', '1.5.1');
define('LCMT_DEV_CONSENT_FILE', __FILE__);
define('LCMT_DEV_CONSENT_DIR', plugin_dir_path(__FILE__));
define('LCMT_DEV_CONSENT_URL', plugin_dir_url(__FILE__));

spl_autoload_register(function ($class) {
    $prefix = 'LcmtDev\\Consent\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = LCMT_DEV_CONSENT_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

// Updates from the GitHub releases
\LcmtDev\Consent\Updater::register(__FILE__);

register_activation_hook(__FILE__, function () {
    require_once __DIR__ . '/src/Admin/Settings.php';
    require_once __DIR__ . '/src/Log/ConsentLog.php';
    (new \LcmtDev\Consent\Log\ConsentLog(new \LcmtDev\Consent\Admin\Settings()))->createTable();
    if (!wp_next_scheduled('lcmt_dev_consent_purge')) {
        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'lcmt_dev_consent_purge');
    }
});

register_deactivation_hook(__FILE__, function () {
    $ts = wp_next_scheduled('lcmt_dev_consent_purge');
    if ($ts) {
        wp_unschedule_event($ts, 'lcmt_dev_consent_purge');
    }
});

add_action('plugins_loaded', function () {
    load_plugin_textdomain('lcmt-dev-consent', false, dirname(plugin_basename(__FILE__)) . '/languages');
    (new \LcmtDev\Consent\Plugin())->boot();
});
