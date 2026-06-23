<?php

namespace LcmtDev\Consent\Admin;

class Settings
{
    public const OPTION_KEY = 'lcmt_dev_consent_settings';

    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION_KEY, []);
            if (!is_array($stored)) {
                $stored = [];
            }
            $this->cache = array_replace_recursive(self::defaults(), $stored);
        }
        return $this->cache;
    }

    public function get(string $key, $default = null)
    {
        $all = $this->all();
        return $all[$key] ?? $default;
    }

    public function save(array $values): void
    {
        $merged = array_replace_recursive($this->all(), $values);
        update_option(self::OPTION_KEY, $merged);
        $this->cache = null;
    }

    public function bumpConsentVersion(): void
    {
        $all = $this->all();
        $all['consent_version'] = (int) ($all['consent_version'] ?? 0) + 1;
        update_option(self::OPTION_KEY, $all);
        $this->cache = null;
    }

    public function effectiveCookieName(): string
    {
        $name = (string) $this->get('cookie_name', 'cookieConsent');
        $version = (int) $this->get('consent_version', 0);
        return $version > 0 ? $name . '_v' . $version : $name;
    }

    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'position' => 'bottom-left',
            'texts' => [
                'title' => 'Cookies and personal data',
                'description' => 'This site uses cookies to improve your navigation performance. Some of these cookies require your consent and others are used on the basis of our legitimate interest for our website.',
                'accept' => 'I accept',
                'refuse' => 'I refuse',
                'personalize' => 'I personalize',
                'back' => 'Back',
                'ok' => 'Ok',
                'service_accept' => 'Accept',
                'service_refuse' => 'Refuse',
                'all_accept' => 'All accept',
                'all_refuse' => 'All refuse',
                'panel_title' => 'Cookies management panel',
            ],
            'privacy_url' => '',
            'appearance' => [
                'bg' => '#ffffff',
                'text' => '#000000',
                'hover_bg' => '#ffeb3b',
                'hover_text' => '#000000',
                'border' => '#000000',
                'radius' => 0,
                'z_index' => 1001,
            ],
            'services' => [
                'googletagmanager' => ['enabled' => false, 'id' => '', 'category' => 'api', 'display_name' => '', 'consent_mode' => false],
                'googleanalytics' => ['enabled' => false, 'id' => '', 'category' => 'analytic', 'display_name' => ''],
                'facebookpixel' => ['enabled' => false, 'id' => '', 'category' => 'ads', 'display_name' => ''],
                'matomo' => ['enabled' => false, 'url' => '', 'site_id' => '', 'category' => 'analytic', 'display_name' => ''],
                'youtube' => ['enabled' => true, 'category' => 'api', 'display_name' => ''],
            ],
            'categories' => [
                'api' => [
                    'name' => 'APIs',
                    'description' => 'APIs allow scripts to be loaded: geolocation, search engines, translations, etc.',
                ],
                'analytic' => [
                    'name' => 'Audience measurement',
                    'description' => 'Audience-measurement cookies generate statistics about site traffic and usage. This data helps us improve the site.',
                ],
                'ads' => [
                    'name' => 'Advertising',
                    'description' => 'Advertising cookies allow targeted ads to be served.',
                ],
            ],
            'cookie_name' => 'cookieConsent',
            'cookie_lifetime_days' => 365,
            'consent_version' => 0,
            'custom_css' => '',
        ];
    }

    public function predefinedServiceMeta(): array
    {
        return [
            'googletagmanager' => [
                'name' => 'Google Tag Manager',
                'description' => 'Tag container used to load analytics and advertising scripts.',
                'uri' => 'https://policies.google.com/privacy',
                'id_fields' => [['key' => 'id', 'label' => 'GTM ID', 'placeholder' => 'GTM-XXXXXXX']],
            ],
            'googleanalytics' => [
                'name' => 'Google Analytics 4',
                'description' => 'Used to generate site-traffic statistics.',
                'uri' => 'https://policies.google.com/privacy',
                'id_fields' => [['key' => 'id', 'label' => 'Measurement ID', 'placeholder' => 'G-XXXXXXXXX']],
            ],
            'facebookpixel' => [
                'name' => 'Facebook Pixel',
                'description' => 'Used to track user actions on Facebook.',
                'uri' => 'https://www.facebook.com/policy.php',
                'id_fields' => [['key' => 'id', 'label' => 'Pixel ID', 'placeholder' => '000000000000000']],
            ],
            'matomo' => [
                'name' => 'Matomo',
                'description' => 'Used to track user actions on the site.',
                'uri' => 'https://matomo.org/privacy-policy/',
                'id_fields' => [
                    ['key' => 'url', 'label' => 'Matomo URL', 'placeholder' => 'https://example.matomo.cloud/'],
                    ['key' => 'site_id', 'label' => 'Site ID', 'placeholder' => '1'],
                ],
            ],
            'youtube' => [
                'name' => 'YouTube',
                'description' => 'Used to embed videos hosted on YouTube. Accepting allows YouTube/Google to set cookies on your device.',
                'uri' => 'https://policies.google.com/privacy',
                'id_fields' => [],
            ],
        ];
    }

    /**
     * Virtual services added to the banner when GTM Consent Mode v2 is enabled.
     * GTM itself always loads server-side; these 4 items toggle the individual
     * gtag consent signals.
     */
    public function consentModeServices(): array
    {
        return [
            'google_analytics_storage' => [
                'signal' => 'analytics_storage',
                'category' => 'analytic',
                'name' => 'Google analytics storage',
                'description' => 'Used to store and access cookies for analytics purposes, to measure site activity and performance.',
            ],
            'google_ad_storage' => [
                'signal' => 'ad_storage',
                'category' => 'ads',
                'name' => 'Google ad storage',
                'description' => 'Used to store and access cookies for advertising purposes, such as conversion tracking and remarketing.',
            ],
            'google_ad_user_data' => [
                'signal' => 'ad_user_data',
                'category' => 'ads',
                'name' => 'Google ad user data',
                'description' => 'Used to send user data to Google to improve ad relevance.',
            ],
            'google_ad_personalization' => [
                'signal' => 'ad_personalization',
                'category' => 'ads',
                'name' => 'Google ad personalization',
                'description' => 'Used to store cookies enabling ad personalization based on past user interactions.',
            ],
        ];
    }
}
