<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Frontend\CookieTable;
use LcmtDev\Consent\Services\CookieRegistry;
use LcmtDev\Consent\Services\ServiceRegistry;

class CookieTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('shortcode_atts')->alias(fn($defaults, $atts) => array_merge($defaults, (array) $atts));
        Functions\when('translate')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);

        // Reset the once-per-request style guard between tests in this process.
        $ref = new \ReflectionProperty(CookieTable::class, 'styleEmitted');
        $ref->setAccessible(true);
        $ref->setValue(null, false);
    }

    private function table(array $option): CookieTable
    {
        Functions\when('get_option')->justReturn($option);
        $settings = new Settings();
        $registry = new ServiceRegistry($settings);
        $cookies = new CookieRegistry($settings, $registry);
        return new CookieTable($cookies, new Translations($settings));
    }

    public function test_render_outputs_table_with_enabled_service_rows(): void
    {
        $html = $this->table(['services' => [
            'googleanalytics' => ['enabled' => true, 'id' => 'G-XXX', 'category' => 'analytic', 'display_name' => ''],
        ]])->render(['essential' => '0']);

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('lcmt-cookies-table', $html);
        $this->assertStringContainsString('_ga', $html);
        $this->assertStringContainsString('Google Analytics', $html);
    }

    public function test_essential_attribute_zero_hides_essential_row(): void
    {
        $html = $this->table([
            'services' => [
                'googleanalytics' => ['enabled' => false, 'id' => '', 'category' => 'analytic', 'display_name' => ''],
                'youtube' => ['enabled' => false, 'category' => 'api', 'display_name' => ''],
            ],
        ])->render(['essential' => '0']);
        $this->assertSame('', $html);
    }

    public function test_essential_row_shown_by_default(): void
    {
        $html = $this->table([
            'services' => [
                'googleanalytics' => ['enabled' => false, 'id' => '', 'category' => 'analytic', 'display_name' => ''],
                'youtube' => ['enabled' => false, 'category' => 'api', 'display_name' => ''],
            ],
            'cookie_lifetime_days' => 365,
        ])->render([]);
        $this->assertStringContainsString('cookieConsent', $html);
    }

    public function test_style_emitted_only_once(): void
    {
        $t = $this->table(['services' => [], 'cookie_lifetime_days' => 365]);
        $first = $t->render([]);
        $second = $t->render([]);
        $this->assertSame(1, substr_count($first, '<style'));
        $this->assertSame(0, substr_count($second, '<style'));
    }
}
