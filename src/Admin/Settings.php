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

    /**
     * Box-shadow presets of the banner, keyed by the `appearance.shadow` setting.
     */
    public static function shadowPresets(): array
    {
        return [
            'none' => 'none',
            'light' => '0 4px 20px rgba(0, 0, 0, 0.08)',
            'medium' => '0 10px 30px rgba(0, 0, 0, 0.15)',
            'strong' => '0 16px 48px rgba(0, 0, 0, 0.25)',
        ];
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
                'title_icon' => 'icon',
                'title_icon_url' => '',
                'shadow' => 'light',
            ],
            'services' => [
                'googletagmanager' => ['enabled' => false, 'id' => '', 'category' => 'api', 'display_name' => '', 'consent_mode' => false],
                'googleanalytics' => ['enabled' => false, 'id' => '', 'category' => 'analytic', 'display_name' => ''],
                'facebookpixel' => ['enabled' => false, 'id' => '', 'category' => 'ads', 'display_name' => ''],
                'matomo' => ['enabled' => false, 'url' => '', 'site_id' => '', 'category' => 'analytic', 'display_name' => ''],
                'youtube' => ['enabled' => false, 'category' => 'api', 'display_name' => ''],
                'googlemaps' => ['enabled' => false, 'category' => 'api', 'display_name' => ''],
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
            'log_enabled' => true,
            'log_retention_months' => 36,
            'service_cookies' => [],
            // Google Site Kit: let its tag load before consent (Consent Mode v2 "advanced")
            'sitekit_advanced' => false,
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
            'googlemaps' => [
                'name' => 'Google Maps',
                'description' => 'Used to embed interactive maps from Google Maps. Accepting allows Google to set cookies on your device.',
                'uri' => 'https://policies.google.com/privacy',
                'id_fields' => [],
            ],
        ];
    }

    /**
     * Curated, accurate default cookie metadata per service for the CNIL cookie
     * table. Purposes are full descriptive sentences (English source strings,
     * localized via the .mo). Admin overrides live in the `service_cookies`
     * option key; code services supply their own via the services filter.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function defaultServiceCookies(): array
    {
        $google = 'https://policies.google.com/privacy';
        return [
            'googleanalytics' => [
                ['name' => '_ga', 'purpose' => 'Registers a unique ID used to generate statistical data on how the visitor uses the site.', 'retention' => '13 months', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
                ['name' => '_gat (or _dc_gtm_<property-id>)', 'purpose' => 'Used to throttle the request rate to Google Analytics.', 'retention' => '1 minute', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
                ['name' => '_gid', 'purpose' => 'Used to distinguish users.', 'retention' => '1 day', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
                ['name' => '_ga_<container-id>', 'purpose' => 'Persists session state for Google Analytics 4.', 'retention' => '13 months', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
            ],
            'googletagmanager' => [
                ['name' => '_dc_gtm_<property-id>', 'purpose' => 'Used to throttle the request rate to scripts loaded through Google Tag Manager.', 'retention' => '1 minute', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
            ],
            'facebookpixel' => [
                ['name' => '_fbp', 'purpose' => 'Used by Meta to deliver and measure advertising and to identify the visitor\'s browser across sites.', 'retention' => '3 months', 'issuer' => 'Meta', 'third_party' => true, 'url' => 'https://www.facebook.com/policy.php'],
                ['name' => '_fbc', 'purpose' => 'Stores the last advertising click to attribute conversions for Meta advertising.', 'retention' => '3 months', 'issuer' => 'Meta', 'third_party' => true, 'url' => 'https://www.facebook.com/policy.php'],
            ],
            'matomo' => [
                ['name' => '_pk_id', 'purpose' => 'Stores a unique visitor ID used to generate site usage statistics.', 'retention' => '13 months', 'issuer' => 'Matomo', 'third_party' => false, 'url' => 'https://matomo.org/privacy-policy/'],
                ['name' => '_pk_ses', 'purpose' => 'Stores temporary session data used to group a visitor\'s actions during a visit.', 'retention' => '30 minutes', 'issuer' => 'Matomo', 'third_party' => false, 'url' => 'https://matomo.org/privacy-policy/'],
            ],
            'youtube' => [
                ['name' => 'VISITOR_INFO1_LIVE', 'purpose' => 'Estimates the visitor\'s bandwidth on pages that embed YouTube videos.', 'retention' => '6 months', 'issuer' => 'Google (YouTube)', 'third_party' => true, 'url' => $google],
                ['name' => 'YSC', 'purpose' => 'Stores a unique ID to keep statistics of the YouTube videos the visitor has seen.', 'retention' => 'Session', 'issuer' => 'Google (YouTube)', 'third_party' => true, 'url' => $google],
            ],
            'googlemaps' => [
                ['name' => 'NID', 'purpose' => 'Stores visitor preferences and information when an embedded Google map is displayed.', 'retention' => '6 months', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
                ['name' => 'CONSENT', 'purpose' => 'Records the visitor\'s cookie-consent state for Google services such as embedded maps.', 'retention' => '2 years', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
            ],
            'google_analytics_storage' => [
                ['name' => '_ga', 'purpose' => 'Registers a unique ID used to generate statistical data on how the visitor uses the site.', 'retention' => '13 months', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
            ],
            'google_ad_storage' => [
                ['name' => '_gcl_au', 'purpose' => 'Stores and tracks ad conversions for Google advertising services.', 'retention' => '3 months', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
            ],
            'google_ad_user_data' => [
                ['name' => 'IDE', 'purpose' => 'Used by Google to measure and personalize advertising based on user data.', 'retention' => '13 months', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
            ],
            'google_ad_personalization' => [
                ['name' => 'NID', 'purpose' => 'Stores preferences used to personalize Google advertising.', 'retention' => '6 months', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
            ],
        ];
    }

    /**
     * Virtual services added to the banner when Consent Mode v2 is on (GTM
     * Consent Mode enabled, or Google Site Kit detected). These 4 items toggle
     * the individual gtag consent signals.
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
