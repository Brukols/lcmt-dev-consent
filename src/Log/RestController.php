<?php

namespace LcmtDev\Consent\Log;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Consent;
use LcmtDev\Consent\Services\ServiceRegistry;

class RestController
{
    public const NAMESPACE = 'lcmt-dev-consent/v1';
    public const ROUTE = '/log';
    private const RATE_LIMIT = 30;       // requests
    private const RATE_WINDOW = 60;      // seconds

    private Settings $settings;
    private ServiceRegistry $registry;
    private ConsentLog $log;

    public function __construct(Settings $settings, ServiceRegistry $registry, ConsentLog $log)
    {
        $this->settings = $settings;
        $this->registry = $registry;
        $this->log = $log;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoute']);
    }

    public function registerRoute(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => function ($request) {
                $nonce = $request->get_header('X-WP-Nonce');
                return (bool) wp_verify_nonce($nonce, 'wp_rest');
            },
            'args' => [
                'event' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public function isValidEvent(string $event): bool
    {
        return in_array($event, ['accept_all', 'reject_all', 'custom', 'withdraw'], true);
    }

    public function rateLimitExceeded(string $ip): bool
    {
        $key = 'lcmt_consent_rl_' . md5($ip);
        $count = (int) get_transient($key);
        $count++;
        set_transient($key, $count, self::RATE_WINDOW);
        return $count > self::RATE_LIMIT;
    }

    public function handle($request)
    {
        if (!(bool) $this->settings->get('log_enabled', true)) {
            return rest_ensure_response(['logged' => false]);
        }

        $event = (string) $request->get_param('event');
        if (!$this->isValidEvent($event)) {
            return rest_ensure_response(['logged' => false]);
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        if ($this->rateLimitExceeded($ip)) {
            return rest_ensure_response(['logged' => false]);
        }

        $cookieName = $this->settings->effectiveCookieName();
        $cookies = Consent::getCookies($cookieName);
        $consentId = $cookies['cid'] ?? '';
        if ($consentId === '') {
            return rest_ensure_response(['logged' => false]);
        }

        $choices = [];
        foreach ($cookies as $key => $status) {
            if ($key === 'cid') {
                continue;
            }
            $choices[$key] = ($status === 'true');
        }

        $this->log->insert([
            'consent_id' => $consentId,
            'event' => $event,
            'choices' => (string) wp_json_encode($choices),
            'cookie_version' => (string) $this->settings->get('consent_version', 0),
        ]);

        return rest_ensure_response(['logged' => true]);
    }
}
