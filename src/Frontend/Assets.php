<?php

namespace LcmtDev\Consent\Frontend;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Consent;
use LcmtDev\Consent\Services\ServiceRegistry;

class Assets
{
    private Settings $settings;
    private ServiceRegistry $registry;
    private Translations $t;

    public function __construct(Settings $settings, ServiceRegistry $registry, Translations $t)
    {
        $this->settings = $settings;
        $this->registry = $registry;
        $this->t = $t;
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueYoutube']);
    }

    public function enqueue(): void
    {
        if (!$this->settings->get('enabled', true) || is_admin()) {
            return;
        }
        $services = $this->registry->all();
        if (empty($services)) {
            return;
        }
        $keys = array_map(fn($s) => $s->key, $services);
        $cookieName = $this->settings->effectiveCookieName();

        if (Consent::isComplete($keys, $cookieName)) {
            return; // Nothing to show, don't load assets.
        }

        $manifest = $this->readManifest();
        $jsFile = $manifest['banner.js'] ?? null;
        $cssFile = $manifest['banner.css'] ?? null;

        if ($cssFile) {
            wp_enqueue_style(
                'lcmt-dev-consent',
                LCMT_DEV_CONSENT_URL . 'assets/dist/' . $cssFile,
                [],
                LCMT_DEV_CONSENT_VERSION
            );
        }
        if ($jsFile) {
            wp_enqueue_script(
                'lcmt-dev-consent',
                LCMT_DEV_CONSENT_URL . 'assets/dist/' . $jsFile,
                [],
                LCMT_DEV_CONSENT_VERSION,
                true
            );
            wp_localize_script('lcmt-dev-consent', 'lcmtConsent', $this->clientConfig());
        }
    }

    /**
     * Enqueue the YouTube placeholder bundle, but only when the placeholder
     * could actually be rendered: service is enabled in settings AND the user
     * hasn't already accepted YouTube. Returning visitors who accepted load
     * zero bytes from this entry.
     */
    public function enqueueYoutube(): void
    {
        if (!$this->settings->get('enabled', true) || is_admin()) {
            return;
        }
        $services = (array) $this->settings->get('services', []);
        if (empty($services['youtube']['enabled'])) {
            return;
        }
        $cookieName = $this->settings->effectiveCookieName();
        if (Consent::isAllowed('youtube', $cookieName)) {
            return;
        }

        $manifest = $this->readManifest();
        $jsFile = $manifest['youtube.js'] ?? null;
        $cssFile = $manifest['youtube.css'] ?? null;

        if ($cssFile) {
            wp_enqueue_style(
                'lcmt-dev-consent-youtube',
                LCMT_DEV_CONSENT_URL . 'assets/dist/' . $cssFile,
                [],
                LCMT_DEV_CONSENT_VERSION
            );
        }
        if ($jsFile) {
            wp_enqueue_script(
                'lcmt-dev-consent-youtube',
                LCMT_DEV_CONSENT_URL . 'assets/dist/' . $jsFile,
                [],
                LCMT_DEV_CONSENT_VERSION,
                true
            );
            // Always localize on the youtube handle too: the banner script may
            // not be enqueued (e.g. user accepted everything except youtube,
            // so the banner is dismissed), and youtube.ts needs the cookie name
            // and lifetime to write the consent cookie.
            wp_localize_script('lcmt-dev-consent-youtube', 'lcmtConsent', $this->clientConfig());
        }
    }

    private function clientConfig(): array
    {
        $services = array_map(fn($s) => $s->toClientConfig(), $this->registry->all());
        $categories = (array) $this->settings->get('categories', []);

        return [
            'cookieName' => $this->settings->effectiveCookieName(),
            'cookieLifetimeDays' => (int) $this->settings->get('cookie_lifetime_days', 365),
            'services' => array_values($services),
            'categories' => $categories,
            'texts' => (array) $this->settings->get('texts', []),
        ];
    }

    private function readManifest(): array
    {
        $path = LCMT_DEV_CONSENT_DIR . 'assets/dist/manifest.json';
        if (!is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
