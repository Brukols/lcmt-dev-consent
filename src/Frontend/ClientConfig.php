<?php

namespace LcmtDev\Consent\Frontend;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Log\RestController;
use LcmtDev\Consent\Services\ServiceRegistry;

class ClientConfig
{
    public static function build(Settings $settings, ServiceRegistry $registry): array
    {
        $services = array_map(fn($s) => $s->toClientConfig(), $registry->all());

        return [
            'cookieName' => $settings->effectiveCookieName(),
            'cookieLifetimeDays' => (int) $settings->get('cookie_lifetime_days', 365),
            'services' => array_values($services),
            'categories' => (array) $settings->get('categories', []),
            'texts' => (array) $settings->get('texts', []),
            'log' => [
                'enabled' => (bool) $settings->get('log_enabled', true),
                'endpoint' => rest_url(RestController::NAMESPACE . RestController::ROUTE),
                'nonce' => wp_create_nonce('wp_rest'),
            ],
        ];
    }
}
