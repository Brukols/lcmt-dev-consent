<?php

namespace LcmtDev\Consent\Frontend;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Consent;
use LcmtDev\Consent\Services\ServiceRegistry;

class ScriptInjector
{
    private Settings $settings;
    private ServiceRegistry $registry;

    public function __construct(Settings $settings, ServiceRegistry $registry)
    {
        $this->settings = $settings;
        $this->registry = $registry;
    }

    public function register(): void
    {
        add_action('wp_head', [$this, 'inject'], 1);
    }

    public function inject(): void
    {
        if (!$this->settings->get('enabled', true) || is_admin()) {
            return;
        }
        $cookieName = $this->settings->effectiveCookieName();
        $cookies = Consent::getCookies($cookieName);

        // GTM Consent Mode v2: GTM loads unconditionally, preceded by gtag consent defaults
        // built from the cookie state. Individual consent signals flip to "granted" via
        // client-side gtag('consent', 'update', …) calls when the user accepts them.
        if ($this->registry->isGtmConsentMode()) {
            $this->emitConsentModeSnippet($cookies);
        }

        foreach ($this->registry->all() as $service) {
            if (($cookies[$service->key] ?? null) !== 'true') {
                continue;
            }
            if (is_callable($service->injectPhp)) {
                $html = call_user_func($service->injectPhp, $service->data);
                if (is_string($html) && $html !== '') {
                    echo $html . "\n";
                }
            }
        }
    }

    private function emitConsentModeSnippet(array $cookies): void
    {
        $gtmId = $this->registry->gtmId();
        if ($gtmId === '') {
            return;
        }
        $signalMap = [
            'google_analytics_storage' => 'analytics_storage',
            'google_ad_storage' => 'ad_storage',
            'google_ad_user_data' => 'ad_user_data',
            'google_ad_personalization' => 'ad_personalization',
        ];
        $defaults = [];
        foreach ($signalMap as $key => $signal) {
            $defaults[$signal] = (($cookies[$key] ?? null) === 'true') ? 'granted' : 'denied';
        }
        $defaultsJson = wp_json_encode($defaults);
        $gtmIdJs = esc_js($gtmId);

        echo "<script>window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){window.dataLayer.push(arguments);};window.gtag('consent','default',{$defaultsJson});</script>\n";
        echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$gtmIdJs}');</script>\n";
    }
}
