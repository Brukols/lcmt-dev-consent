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
}
