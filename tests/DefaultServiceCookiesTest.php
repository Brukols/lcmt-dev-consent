<?php

namespace LcmtDev\Consent\Tests;

use LcmtDev\Consent\Admin\Settings;

class DefaultServiceCookiesTest extends TestCase
{
    public function test_service_cookies_default_is_empty_array(): void
    {
        $this->assertSame([], Settings::defaults()['service_cookies']);
    }

    public function test_ga_defaults_have_descriptive_purposes(): void
    {
        $all = Settings::defaultServiceCookies();
        $this->assertArrayHasKey('googleanalytics', $all);

        $names = array_column($all['googleanalytics'], 'name');
        $this->assertContains('_ga', $names);
        $this->assertContains('_gid', $names);

        $ga = null;
        foreach ($all['googleanalytics'] as $row) {
            if ($row['name'] === '_ga') {
                $ga = $row;
            }
        }
        $this->assertNotNull($ga);
        $this->assertSame('13 months', $ga['retention']);
        $this->assertTrue($ga['third_party']);
        // Descriptive sentence, not a one-word category.
        $this->assertGreaterThan(20, strlen($ga['purpose']));
    }

    public function test_every_row_has_all_keys(): void
    {
        foreach (Settings::defaultServiceCookies() as $rows) {
            foreach ($rows as $row) {
                foreach (['name', 'purpose', 'retention', 'issuer', 'third_party', 'url'] as $k) {
                    $this->assertArrayHasKey($k, $row);
                }
            }
        }
    }
}
