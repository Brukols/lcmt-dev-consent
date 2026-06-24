<?php

namespace LcmtDev\Consent\Tests;

use LcmtDev\Consent\Admin\Settings;

class SettingsDefaultsTest extends TestCase
{
    public function test_defaults_include_log_options(): void
    {
        $defaults = Settings::defaults();

        $this->assertArrayHasKey('log_enabled', $defaults);
        $this->assertTrue($defaults['log_enabled']);

        $this->assertArrayHasKey('log_retention_months', $defaults);
        $this->assertSame(36, $defaults['log_retention_months']);
    }

    public function test_youtube_and_googlemaps_disabled_by_default(): void
    {
        $defaults = Settings::defaults();
        $this->assertArrayHasKey('youtube', $defaults['services']);
        $this->assertArrayHasKey('googlemaps', $defaults['services']);
        $this->assertFalse($defaults['services']['youtube']['enabled']);
        $this->assertFalse($defaults['services']['googlemaps']['enabled']);
        $this->assertSame('api', $defaults['services']['googlemaps']['category']);
    }

    public function test_predefined_meta_includes_googlemaps(): void
    {
        $meta = (new Settings())->predefinedServiceMeta();
        $this->assertArrayHasKey('googlemaps', $meta);
        $this->assertSame('Google Maps', $meta['googlemaps']['name']);
    }

    public function test_default_cookies_include_googlemaps(): void
    {
        $all = Settings::defaultServiceCookies();
        $this->assertArrayHasKey('googlemaps', $all);
        $names = array_column($all['googlemaps'], 'name');
        $this->assertContains('NID', $names);
    }
}
