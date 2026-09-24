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

        // Consent Mode v2: gtag consent defaults built from the cookie state, printed
        // before any Google tag. Individual signals flip to "granted" via client-side
        // gtag('consent', 'update', …) calls when the user accepts them.
        if ($this->registry->isConsentMode()) {
            $this->emitConsentDefaults($cookies);
        }
        // GTM Consent Mode: GTM loads unconditionally, right after the defaults.
        if ($this->registry->isGtmConsentMode()) {
            $this->emitGtmLoader();
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

    private function emitConsentDefaults(array $cookies): void
    {
        $defaults = [];
        foreach ($this->settings->consentModeServices() as $key => $meta) {
            $defaults[$meta['signal']] = (($cookies[$key] ?? null) === 'true') ? 'granted' : 'denied';
        }

        $js = "window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){window.dataLayer.push(arguments);};"
            . "window.gtag('consent','default'," . wp_json_encode($defaults) . ");";

        // Site Kit's own Consent Mode setting prints a second, all-denied default
        // after this one. Updates always win over defaults, so granted signals are
        // repeated as an update to survive it.
        $granted = array_filter($defaults, fn($state) => $state === 'granted');
        if ($granted && $this->registry->isSiteKitDetected()) {
            $js .= "window.gtag('consent','update'," . wp_json_encode($granted) . ");";
        }

        echo "<script>{$js}</script>\n";
    }

    private function emitGtmLoader(): void
    {
        $gtmIdJs = esc_js($this->registry->gtmId());
        if ($gtmIdJs === '') {
            return;
        }
        echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$gtmIdJs}');</script>\n";
    }
}
