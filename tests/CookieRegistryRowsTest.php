<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Services\CookieRegistry;
use LcmtDev\Consent\Services\ServiceRegistry;

class CookieRegistryRowsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('__')->returnArg(1);
    }

    private function reg(array $option): CookieRegistry
    {
        Functions\when('get_option')->justReturn($option);
        $settings = new Settings();
        return new CookieRegistry($settings, new ServiceRegistry($settings));
    }

    public function test_all_rows_includes_enabled_service_cookies_with_service_name(): void
    {
        $reg = $this->reg(['services' => [
            'googleanalytics' => ['enabled' => true, 'id' => 'G-XXX', 'category' => 'analytic', 'display_name' => ''],
        ]]);
        $rows = $reg->allRows(false);
        $names = array_column($rows, 'name');
        $this->assertContains('_ga', $names);
        foreach ($rows as $r) {
            $this->assertArrayHasKey('service', $r);
            $this->assertNotSame('', $r['service']);
        }
    }

    public function test_disabled_service_contributes_no_rows(): void
    {
        // Explicitly disable every service so that no service is active,
        // independent of the shipped defaults.
        $reg = $this->reg(['services' => [
            'googleanalytics' => ['enabled' => false, 'id' => '', 'category' => 'analytic', 'display_name' => ''],
            'youtube' => ['enabled' => false, 'category' => 'api', 'display_name' => ''],
            'googlemaps' => ['enabled' => false, 'category' => 'api', 'display_name' => ''],
        ]]);
        $this->assertSame([], $reg->allRows(false));
    }

    public function test_essential_row_present_by_default_and_first_party(): void
    {
        $reg = $this->reg(['cookie_lifetime_days' => 365]);
        $rows = $reg->allRows(true);
        $this->assertNotEmpty($rows);
        $essential = $rows[0];
        $this->assertSame('cookieConsent', $essential['name']);
        $this->assertFalse($essential['third_party']);
        $this->assertStringContainsString('365', $essential['retention']);
    }
}
