<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\SettingsPage;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Integrations\SiteKit;
use LcmtDev\Consent\Log\ConsentLog;
use LcmtDev\Consent\Services\ServiceRegistry;
use LcmtDev\Consent\Tests\Fakes\FakeWpdb;

class SettingsPageSiteKitTest extends TestCase
{
    private const STORED_GA = ['enabled' => true, 'id' => 'G-OLD123', 'category' => 'analytic', 'display_name' => ''];

    private function sanitizeServices(array $input, bool $siteKitActive): array
    {
        $options = [
            Settings::OPTION_KEY => ['services' => ['googleanalytics' => self::STORED_GA]],
            SiteKit::ANALYTICS_OPTION => ['useSnippet' => true, 'googleTagID' => 'GT-XYZ789'],
            SiteKit::ACTIVE_MODULES_OPTION => ['analytics-4'],
        ];
        Functions\when('get_option')->alias(fn($name, $default = false) => $options[$name] ?? $default);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_key')->returnArg(1);
        Functions\when('esc_url_raw')->returnArg(1);

        $settings = new Settings();
        $registry = new ServiceRegistry($settings, new SiteKit($settings, fn() => $siteKitActive));
        $page = new SettingsPage($settings, $registry, new Translations($settings), new ConsentLog($settings, new FakeWpdb()));

        $method = new \ReflectionMethod($page, 'sanitizeForTab');
        $method->setAccessible(true);
        return $method->invoke($page, 'services', ['services' => $input])['services'];
    }

    public function test_disabled_rows_keep_their_stored_settings_under_site_kit(): void
    {
        // Disabled inputs are not submitted: the GA row is missing from the input.
        $services = $this->sanitizeServices(['facebookpixel' => ['enabled' => '1', 'id' => '123']], true);

        $this->assertSame(self::STORED_GA, $services['googleanalytics']);
        $this->assertTrue($services['facebookpixel']['enabled']);
    }

    public function test_rows_are_saved_normally_without_site_kit(): void
    {
        $services = $this->sanitizeServices(['googleanalytics' => ['id' => 'G-NEW456']], false);

        $this->assertFalse($services['googleanalytics']['enabled']);
        $this->assertSame('G-NEW456', $services['googleanalytics']['id']);
    }
}
