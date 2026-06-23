<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Admin\SettingsPage;
use LcmtDev\Consent\Log\ConsentLog;
use LcmtDev\Consent\Services\ServiceRegistry;
use LcmtDev\Consent\Tests\Fakes\FakeWpdb;

class CookieSanitizeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->justReturn([]);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('sanitize_key')->alias(fn($k) => preg_replace('/[^a-z0-9_]/', '', strtolower($k)));
    }

    private function page(): SettingsPage
    {
        $settings = new Settings();
        $registry = new ServiceRegistry($settings);
        $log = new ConsentLog($settings, new FakeWpdb());
        return new SettingsPage($settings, $registry, new Translations($settings), $log);
    }

    public function test_sanitize_drops_empty_rows_and_reindexes(): void
    {
        $out = $this->page()->sanitizeServiceCookies([
            'googleanalytics' => [
                5 => ['name' => '_ga', 'purpose' => 'P', 'retention' => '13 months', 'issuer' => 'Google', 'third_party' => '1', 'url' => 'https://x.test'],
                6 => ['name' => '', 'purpose' => 'ignored'],
            ],
        ]);

        $this->assertArrayHasKey('googleanalytics', $out);
        $this->assertCount(1, $out['googleanalytics']);
        $this->assertSame(0, array_keys($out['googleanalytics'])[0]); // re-indexed
        $row = $out['googleanalytics'][0];
        $this->assertSame('_ga', $row['name']);
        $this->assertTrue($row['third_party']);
        $this->assertSame('https://x.test', $row['url']);
    }

    public function test_sanitize_skips_service_with_only_empty_rows(): void
    {
        $out = $this->page()->sanitizeServiceCookies([
            'matomo' => [['name' => '', 'purpose' => '']],
        ]);
        $this->assertArrayNotHasKey('matomo', $out);
    }
}
