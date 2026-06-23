<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Frontend\Assets;
use LcmtDev\Consent\Services\ServiceRegistry;

class ClientConfigTest extends TestCase
{
    public function test_client_config_includes_log_block(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('rest_url')->alias(fn($p) => 'https://example.test/wp-json/' . ltrim($p, '/'));
        Functions\when('wp_create_nonce')->justReturn('nonce123');

        $settings = new Settings();
        $registry = new ServiceRegistry($settings);
        $assets = new Assets($settings, $registry, new Translations($settings));

        $ref = new \ReflectionMethod($assets, 'clientConfig');
        $ref->setAccessible(true);
        $config = $ref->invoke($assets);

        $this->assertArrayHasKey('log', $config);
        $this->assertTrue($config['log']['enabled']);
        $this->assertSame('https://example.test/wp-json/lcmt-dev-consent/v1/log', $config['log']['endpoint']);
        $this->assertSame('nonce123', $config['log']['nonce']);
    }
}
