<?php
/**
 * Plugin Name: LCMT Dev — Consent
 * Plugin URI:  https://amaurylecomte.com
 * Description: Lightweight, performance-focused cookie consent banner. Loads banner JS/CSS only when consent is pending; injects accepted services server-side.
 * Version:     1.0.0
 * Author:      Amaury Lecomte
 * Author URI:  https://amaurylecomte.com
 * Text Domain: lcmt-dev-consent
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LCMT_DEV_CONSENT_VERSION', '1.0.0');
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

add_action('plugins_loaded', function () {
    load_plugin_textdomain('lcmt-dev-consent', false, dirname(plugin_basename(__FILE__)) . '/languages');
    (new \LcmtDev\Consent\Plugin())->boot();
});
