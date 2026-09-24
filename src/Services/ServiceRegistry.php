<?php

namespace LcmtDev\Consent\Services;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Integrations\SiteKit;

class ServiceRegistry
{
    private Settings $settings;
    private ?SiteKit $siteKit;
    /** @var Service[]|null */
    private ?array $services = null;

    public function __construct(Settings $settings, ?SiteKit $siteKit = null)
    {
        $this->settings = $settings;
        $this->siteKit = $siteKit;
    }

    /** @return Service[] */
    public function all(): array
    {
        if ($this->services !== null) {
            return $this->services;
        }

        $services = [];

        // UI-configured services
        $uiServices = $this->settings->get('services', []);
        $meta = $this->settings->predefinedServiceMeta();
        $gtmInConsentMode = $this->isGtmConsentMode();
        $siteKitTagId = $this->isSiteKitDetected() ? $this->siteKit->tagId() : '';
        $siteKitManaged = $this->siteKitManagedServices();

        foreach ($uiServices as $key => $config) {
            if (empty($config['enabled'])) {
                continue;
            }
            // In GTM Consent Mode v2, GTM loads unconditionally and isn't a user-facing toggle.
            if ($key === 'googletagmanager' && $gtmInConsentMode) {
                continue;
            }
            // Site Kit prints the Google tag: its own GA / GTM would double-count or
            // bypass its consent handling. Settings are kept for when Site Kit goes away.
            if (in_array($key, $siteKitManaged, true)) {
                continue;
            }
            $m = $meta[$key] ?? [];
            $data = [];
            foreach (($m['id_fields'] ?? []) as $field) {
                $fk = $field['key'];
                if (!empty($config[$fk])) {
                    $data[$fk] = (string) $config[$fk];
                }
            }
            if (!empty($m['id_fields']) && empty($data)) {
                continue; // expected ID fields but none provided
            }
            $category = !empty($config['category']) ? (string) $config['category'] : ($m['category'] ?? 'api');
            $displayName = !empty($config['display_name']) ? (string) $config['display_name'] : ($m['name'] ?? $key);

            $services[$key] = new Service([
                'key' => $key,
                'name' => $displayName,
                'description' => $m['description'] ?? '',
                'category' => $category,
                'uri' => $m['uri'] ?? '',
                'data' => $data,
                'inject_js' => $this->builtinInjectJs($key),
                'inject_php' => $this->builtinInjectPhp($key),
                'source' => 'ui',
            ]);
        }

        // Consent Mode v2 virtual services — shown when GTM has Consent Mode enabled
        // or Site Kit prints its tag.
        if ($this->isConsentMode()) {
            $signalData = [];
            // Basic mode: Site Kit's tag is blocked until consent, so the banner loads it on first accept.
            if ($siteKitTagId !== '' && !$this->siteKit->isAdvancedMode()) {
                $signalData['sitekit_id'] = $siteKitTagId;
            }
            foreach ($this->settings->consentModeServices() as $key => $meta2) {
                $services[$key] = new Service([
                    'key' => $key,
                    'name' => $meta2['name'],
                    'description' => $meta2['description'],
                    'category' => $meta2['category'],
                    'uri' => 'https://support.google.com/analytics/answer/9976101',
                    'data' => ['signal' => $meta2['signal']] + $signalData,
                    'source' => 'ui',
                ]);
            }
        }

        // Filter-registered services
        $extra = apply_filters('lcmt_dev_consent_services', []);
        if (is_array($extra)) {
            foreach ($extra as $args) {
                if (!is_array($args) || empty($args['key'])) {
                    continue;
                }
                $args['source'] = 'code';
                $svc = new Service($args);
                if (!isset($services[$svc->key])) {
                    $services[$svc->key] = $svc;
                }
            }
        }

        $this->services = array_values($services);
        return $this->services;
    }

    /** @return Service[] */
    public function filterRegistered(): array
    {
        return array_values(array_filter($this->all(), fn($s) => $s->source === 'code'));
    }

    public function keys(): array
    {
        return array_map(fn(Service $s) => $s->key, $this->all());
    }

    public function find(string $key): ?Service
    {
        foreach ($this->all() as $s) {
            if ($s->key === $key) return $s;
        }
        return null;
    }

    /**
     * Whether gtag consent signals are managed: GTM Consent Mode, or a detected Site Kit tag.
     */
    public function isConsentMode(): bool
    {
        return $this->isGtmConsentMode() || $this->isSiteKitDetected();
    }

    public function isSiteKitDetected(): bool
    {
        return $this->siteKit !== null && $this->siteKit->isDetected();
    }

    public function siteKit(): ?SiteKit
    {
        return $this->siteKit;
    }

    public function isGtmConsentMode(): bool
    {
        if (in_array('googletagmanager', $this->siteKitManagedServices(), true)) {
            return false;
        }
        $gtm = (array) ($this->settings->get('services', [])['googletagmanager'] ?? []);
        return !empty($gtm['enabled']) && !empty($gtm['consent_mode']) && !empty($gtm['id']);
    }

    /**
     * Predefined services switched off while Site Kit prints the Google tag:
     * Google Analytics comes with Site Kit, and GTM goes through Site Kit's
     * Tag Manager module.
     *
     * @return string[]
     */
    public function siteKitManagedServices(): array
    {
        return $this->isSiteKitDetected() ? ['googleanalytics', 'googletagmanager'] : [];
    }

    public function gtmId(): string
    {
        return (string) ($this->settings->get('services', [])['googletagmanager']['id'] ?? '');
    }

    private function builtinInjectJs(string $key): ?string
    {
        // These are JS arrow-function bodies stored as strings. The front-end
        // services-loader.ts has built-in implementations keyed by service key,
        // so we signal which one to run by name. Returning null lets the
        // client-side code look up its built-in injector by key.
        return null;
    }

    private function builtinInjectPhp(string $key): ?callable
    {
        switch ($key) {
            case 'googletagmanager':
                return function (array $data): string {
                    $id = esc_js($data['id'] ?? '');
                    if (!$id) return '';
                    return "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$id}');</script>";
                };
            case 'googleanalytics':
                return function (array $data): string {
                    $id = esc_js($data['id'] ?? '');
                    if (!$id) return '';
                    return "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$id}\"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$id}');</script>";
                };
            case 'facebookpixel':
                return function (array $data): string {
                    $id = esc_js($data['id'] ?? '');
                    if (!$id) return '';
                    return "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{$id}');fbq('track','PageView');</script>";
                };
            case 'matomo':
                return function (array $data): string {
                    $url = esc_js(rtrim((string) ($data['url'] ?? ''), '/') . '/');
                    $siteId = esc_js($data['site_id'] ?? '');
                    if (!$url || !$siteId) return '';
                    return "<script>var _paq=window._paq=window._paq||[];_paq.push(['trackPageView']);_paq.push(['enableLinkTracking']);(function(){var u='{$url}';_paq.push(['setTrackerUrl',u+'matomo.php']);_paq.push(['setSiteId','{$siteId}']);var d=document,g=d.createElement('script'),s=d.getElementsByTagName('script')[0];g.async=true;g.src=u+'matomo.js';s.parentNode.insertBefore(g,s);})();</script>";
                };
        }
        return null;
    }
}
