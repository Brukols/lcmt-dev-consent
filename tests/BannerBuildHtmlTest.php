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

    private function bannerHtml(array $option): string
    {
        Functions\when('get_option')->justReturn($option);
        $settings = new Settings();
        $banner = new Banner($settings, new ServiceRegistry($settings), new Translations($settings));
        return $banner->buildHtml();
    }

    public function test_title_icon_defaults_to_svg_icon(): void
    {
        $html = $this->bannerHtml([
            'services' => ['googleanalytics' => ['enabled' => true, 'id' => 'G', 'category' => 'analytic', 'display_name' => '']],
        ]);
        // SVG icon before BOTH titles (main + panel).
        $this->assertSame(2, substr_count($html, 'lcmt-consent__title-icon'));
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_title_icon_emoji_mode_renders_cookie_emoji(): void
    {
        $html = $this->bannerHtml([
            'appearance' => ['title_icon' => 'emoji'],
            'services' => ['googleanalytics' => ['enabled' => true, 'id' => 'G', 'category' => 'analytic', 'display_name' => '']],
        ]);
        $this->assertStringContainsString('🍪', $html);
        $this->assertStringContainsString('lcmt-consent__title-icon', $html);
        $this->assertStringNotContainsString('<svg', $html);
    }

    public function test_title_icon_custom_renders_img_with_src(): void
    {
        $html = $this->bannerHtml([
            'appearance' => ['title_icon' => 'custom', 'title_icon_url' => 'https://x.test/cookie.svg'],
            'services' => ['googleanalytics' => ['enabled' => true, 'id' => 'G', 'category' => 'analytic', 'display_name' => '']],
        ]);
        $this->assertSame(2, substr_count($html, 'lcmt-consent__title-icon'));
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('src="https://x.test/cookie.svg"', $html);
        $this->assertStringContainsString('alt=""', $html);
        $this->assertStringNotContainsString('<svg', $html);
    }

    public function test_title_icon_custom_without_url_falls_back_to_svg(): void
    {
        $html = $this->bannerHtml([
            'appearance' => ['title_icon' => 'custom', 'title_icon_url' => ''],
            'services' => ['googleanalytics' => ['enabled' => true, 'id' => 'G', 'category' => 'analytic', 'display_name' => '']],
        ]);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_title_icon_none_renders_nothing(): void
    {
        $html = $this->bannerHtml([
            'appearance' => ['title_icon' => 'none'],
            'services' => ['googleanalytics' => ['enabled' => true, 'id' => 'G', 'category' => 'analytic', 'display_name' => '']],
        ]);
        $this->assertStringNotContainsString('lcmt-consent__title-icon', $html);
        $this->assertStringNotContainsString('🍪', $html);
    }
}
