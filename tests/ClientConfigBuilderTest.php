<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Frontend\ClientConfig;
use LcmtDev\Consent\Services\ServiceRegistry;

class ClientConfigBuilderTest extends TestCase
{
    public function test_build_returns_expected_keys(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('rest_url')->alias(fn($p) => 'https://example.test/wp-json/' . ltrim($p, '/'));
        Functions\when('wp_create_nonce')->justReturn('nonce123');

        $settings = new Settings();
        $config = ClientConfig::build($settings, new ServiceRegistry($settings));

        foreach (['cookieName', 'cookieLifetimeDays', 'services', 'categories', 'texts', 'log'] as $k) {
            $this->assertArrayHasKey($k, $config);
        }
        $this->assertSame('https://example.test/wp-json/lcmt-dev-consent/v1/log', $config['log']['endpoint']);
    }
}
