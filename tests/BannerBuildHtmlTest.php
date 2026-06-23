<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Frontend\Banner;
use LcmtDev\Consent\Services\ServiceRegistry;

class BannerBuildHtmlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->justReturn([
            'services' => ['googleanalytics' => ['enabled' => true, 'id' => 'G-XXX', 'category' => 'analytic', 'display_name' => '']],
        ]);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('__')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('translate')->returnArg(1);
    }

    public function test_build_html_contains_both_views(): void
    {
        $settings = new Settings();
        $registry = new ServiceRegistry($settings);
        $banner = new Banner($settings, $registry, new Translations($settings));

        $html = $banner->buildHtml();
        $this->assertStringContainsString('id="lcmt-consent"', $html);
        $this->assertStringContainsString('id="lcmt-consent-main"', $html);
        $this->assertStringContainsString('id="lcmt-consent-panel"', $html);
    }
}
