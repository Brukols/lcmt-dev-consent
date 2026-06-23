<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Services\CookieRegistry;
use LcmtDev\Consent\Services\Service;
use LcmtDev\Consent\Services\ServiceRegistry;

class CookieRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('esc_url_raw')->returnArg(1);
    }

    private function reg(array $option = []): CookieRegistry
    {
        Functions\when('get_option')->justReturn($option);
        $settings = new Settings();
        return new CookieRegistry($settings, new ServiceRegistry($settings));
    }

    public function test_normalize_casts_and_trims(): void
    {
        $row = CookieRegistry::normalizeRow([
            'name' => '  _ga  ', 'purpose' => ' P ', 'retention' => '13 months',
            'issuer' => 'Google', 'third_party' => '1', 'url' => 'https://x.test',
        ]);
        $this->assertSame('_ga', $row['name']);
        $this->assertSame('P', $row['purpose']);
        $this->assertTrue($row['third_party']);
        $this->assertSame('https://x.test', $row['url']);
    }

    public function test_normalize_blanks_bad_url_and_fills_missing(): void
    {
        $row = CookieRegistry::normalizeRow(['name' => '_x', 'url' => 'javascript:alert(1)']);
        $this->assertSame('', $row['url']);
        $this->assertSame('', $row['purpose']);
        $this->assertFalse($row['third_party']);
        $this->assertSame(['name', 'purpose', 'retention', 'issuer', 'third_party', 'url'], array_keys($row));
    }

    public function test_for_service_uses_shipped_default(): void
    {
        $reg = $this->reg();
        $svc = new Service(['key' => 'googleanalytics', 'name' => 'Google Analytics']);
        $rows = $reg->forService($svc);
        $this->assertContains('_ga', array_column($rows, 'name'));
    }

    public function test_admin_override_wins_over_default(): void
    {
        $reg = $this->reg(['service_cookies' => [
            'googleanalytics' => [['name' => '_only', 'purpose' => 'x', 'retention' => 'y', 'issuer' => 'z', 'third_party' => false, 'url' => '']],
        ]]);
        $svc = new Service(['key' => 'googleanalytics', 'name' => 'Google Analytics']);
        $rows = $reg->forService($svc);
        $this->assertSame(['_only'], array_column($rows, 'name'));
    }

    public function test_filter_cookies_used_for_code_service_without_default(): void
    {
        $reg = $this->reg();
        $svc = new Service(['key' => 'hotjar', 'name' => 'Hotjar', 'cookies' => [
            ['name' => '_hjid', 'purpose' => 'p', 'retention' => '1 year', 'issuer' => 'Hotjar', 'third_party' => true, 'url' => ''],
        ]]);
        $this->assertSame(['_hjid'], array_column($reg->forService($svc), 'name'));
    }
}
