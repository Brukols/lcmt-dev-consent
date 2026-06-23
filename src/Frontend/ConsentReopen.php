<?php

namespace LcmtDev\Consent\Frontend;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Consent;
use LcmtDev\Consent\Log\RestController;
use LcmtDev\Consent\Services\ServiceRegistry;

class ConsentReopen
{
    public const PANEL_ROUTE = '/panel';

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
        add_action('rest_api_init', [$this, 'registerRoute']);
        add_action('init', function () {
            add_shortcode('lcmt_cookies_settings', [$this, 'shortcode']);
        });
        add_action('wp_footer', [$this, 'maybeOutputOpener'], 30);
    }

    public function registerRoute(): void
    {
        register_rest_route(RestController::NAMESPACE, self::PANEL_ROUTE, [
            'methods' => 'GET',
            'callback' => [$this, 'panel'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** @return array{html:string} */
    public function panel(): array
    {
        $banner = new Banner($this->settings, $this->registry, $this->t);
        return ['html' => $banner->buildHtml()];
    }

    /** @param array<string,mixed>|string $atts */
    public function shortcode($atts = []): string
    {
        $atts = shortcode_atts(['label' => __('Gérer les cookies', 'lcmt-dev-consent')], $atts);
        return '<button type="button" class="lcmt-open-consent">' . esc_html((string) $atts['label']) . '</button>';
    }

    public function isDecided(): bool
    {
        if (!$this->settings->get('enabled', true) || is_admin()) {
            return false;
        }
        $services = $this->registry->all();
        if (empty($services)) {
            return false;
        }
        $keys = array_map(fn($s) => $s->key, $services);
        return Consent::isComplete($keys, $this->settings->effectiveCookieName());
    }

    private function manifest(): array
    {
        $path = LCMT_DEV_CONSENT_DIR . 'assets/dist/manifest.json';
        if (!is_readable($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    public function maybeOutputOpener(): void
    {
        if (!$this->isDecided()) {
            return; // pending → full banner is present and provides open()
        }

        $manifest = $this->manifest();
        $jsFile = $manifest['banner.js'] ?? null;
        $cssFile = $manifest['banner.css'] ?? null;
        if (!$jsFile || !$cssFile) {
            return;
        }

        $config = ClientConfig::build($this->settings, $this->registry);
        $reopen = [
            'panelUrl' => rest_url(RestController::NAMESPACE . self::PANEL_ROUTE),
            'cssUrl' => LCMT_DEV_CONSENT_URL . 'assets/dist/' . $cssFile,
            'jsUrl' => LCMT_DEV_CONSENT_URL . 'assets/dist/' . $jsFile,
        ];

        $configJson = wp_json_encode($config);
        $reopenJson = wp_json_encode($reopen);
        ?>
        <script id="lcmt-consent-opener">
        window.lcmtConsent = window.lcmtConsent || <?= $configJson ?>;
        window.lcmtConsentReopen = <?= $reopenJson ?>;
        (function () {
            var loading = false;
            function ensureAndOpen() {
                if (window.__lcmtBannerReady) { window.lcmtConsent.open && window.lcmtConsent.open(); return; }
                if (loading) { return; }
                loading = true;
                window.__lcmtConsentOpenRequested = true;
                var cfg = window.lcmtConsentReopen;
                fetch(cfg.panelUrl, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.html && !document.getElementById('lcmt-consent')) {
                            var wrap = document.createElement('div');
                            wrap.innerHTML = data.html.trim();
                            if (wrap.firstChild) { document.body.appendChild(wrap.firstChild); }
                        }
                        var link = document.createElement('link');
                        link.rel = 'stylesheet'; link.href = cfg.cssUrl;
                        document.head.appendChild(link);
                        var s = document.createElement('script');
                        s.src = cfg.jsUrl; s.async = true;
                        document.body.appendChild(s);
                    })
                    .catch(function () { loading = false; });
            }
            function isTrigger(el) {
                if (!el || !el.closest) { return false; }
                if (el.closest('.lcmt-open-consent')) { return true; }
                var a = el.closest('a');
                return !!(a && a.hash === '#cookie-settings');
            }
            document.addEventListener('click', function (e) {
                if (window.__lcmtBannerReady) { return; } // banner.ts handles it once loaded
                if (isTrigger(e.target)) { e.preventDefault(); ensureAndOpen(); }
            });
            if (typeof window.lcmtConsent.open !== 'function') {
                window.lcmtConsent.open = ensureAndOpen;
            }
        })();
        </script>
        <?php
    }
}
