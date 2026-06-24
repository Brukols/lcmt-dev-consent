<?php

require_once __DIR__ . '/../vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('LCMT_DEV_CONSENT_DIR')) {
    define('LCMT_DEV_CONSENT_DIR', dirname(__DIR__) . '/');
}
if (!defined('LCMT_DEV_CONSENT_URL')) {
    define('LCMT_DEV_CONSENT_URL', 'https://example.test/wp-content/plugins/lcmt-dev-consent/');
}

// Mirror the plugin's runtime autoloader so tests load src/ classes.
spl_autoload_register(function ($class) {
    $prefix = 'LcmtDev\\Consent\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (strpos($relative, 'Tests\\') === 0) {
        $relative = substr($relative, strlen('Tests\\'));
        $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    } else {
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    }
    if (is_readable($path)) {
        require_once $path;
    }
});
