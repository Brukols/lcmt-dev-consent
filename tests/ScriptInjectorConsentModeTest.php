<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Frontend\ScriptInjector;
use LcmtDev\Consent\Integrations\SiteKit;
use LcmtDev\Consent\Services\ServiceRegistry;

class ScriptInjectorConsentModeTest extends TestCase
{
    protected function tearDown(): void
    {
        $_COOKIE = [];
        parent::tearDown();
    }

    private function inject(array $consentSettings = [], bool $siteKitActive = true): string
    {
        $options = [
            Settings::OPTION_KEY => $consentSettings,
            SiteKit::ANALYTICS_OPTION => ['useSnippet' => true, 'googleTagID' => 'GT-XYZ789'],
            SiteKit::ACTIVE_MODULES_OPTION => ['analytics-4'],
        ];
        Functions\when('get_option')->alias(fn($name, $default = false) => $options[$name] ?? $default);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('esc_js')->returnArg();

        $settings = new Settings();
        $registry = new ServiceRegistry($settings, new SiteKit($settings, fn() => $siteKitActive));

        ob_start();
        (new ScriptInjector($settings, $registry))->inject();
        return (string) ob_get_clean();
    }

    public function test_site_kit_gets_denied_defaults_without_gtm(): void
    {
        $html = $this->inject();

        $this->assertStringContainsString(
            "gtag('consent','default',{\"analytics_storage\":\"denied\",\"ad_storage\":\"denied\",\"ad_user_data\":\"denied\",\"ad_personalization\":\"denied\"})",
            $html
        );
        $this->assertStringNotContainsString('gtm.js', $html);
        $this->assertStringNotContainsString("'update'", $html);
    }

    public function test_site_kit_granted_signals_are_also_sent_as_an_update(): void
    {
        $_COOKIE['cookieConsent'] = '!google_analytics_storage=true!google_ad_storage=false';

        $html = $this->inject();

        $this->assertStringContainsString("\"analytics_storage\":\"granted\"", $html);
        // An update beats any later default, e.g. the one Site Kit's own Consent Mode prints
        $this->assertStringContainsString("gtag('consent','update',{\"analytics_storage\":\"granted\"})", $html);
    }

    public function test_gtm_consent_mode_output_is_unchanged(): void
    {
        $_COOKIE['cookieConsent'] = '!google_analytics_storage=true';

        $html = $this->inject(
            ['services' => ['googletagmanager' => ['enabled' => true, 'id' => 'GTM-ABC', 'consent_mode' => true]]],
            false
        );

        $this->assertStringContainsString("gtag('consent','default'", $html);
        $this->assertStringContainsString("gtm.js", $html);
        $this->assertStringNotContainsString("'update'", $html);
    }

    public function test_nothing_without_consent_mode(): void
    {
        $this->assertSame('', $this->inject([], false));
    }
}
