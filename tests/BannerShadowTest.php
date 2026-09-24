<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Frontend\Banner;
use LcmtDev\Consent\Services\ServiceRegistry;

class BannerShadowTest extends TestCase
{
    private function styleVars(array $appearance): string
    {
        Functions\when('get_option')->justReturn(['appearance' => $appearance]);
        Functions\when('is_admin')->justReturn(false);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('wp_strip_all_tags')->returnArg(1);

        $settings = new Settings();
        $banner = new Banner($settings, new ServiceRegistry($settings), new Translations($settings));

        ob_start();
        $banner->renderStyleVars();
        return (string) ob_get_clean();
    }

    public function test_light_shadow_by_default(): void
    {
        $this->assertStringContainsString('--lcmt-consent-shadow:0 4px 20px rgba(0, 0, 0, 0.08)', $this->styleVars([]));
    }

    public function test_selected_preset_is_used(): void
    {
        $this->assertStringContainsString('--lcmt-consent-shadow:0 16px 48px rgba(0, 0, 0, 0.25)', $this->styleVars(['shadow' => 'strong']));
        $this->assertStringContainsString('--lcmt-consent-shadow:none', $this->styleVars(['shadow' => 'none']));
    }

    public function test_unknown_preset_falls_back_to_default(): void
    {
        $this->assertStringContainsString('--lcmt-consent-shadow:0 4px 20px rgba(0, 0, 0, 0.08)', $this->styleVars(['shadow' => 'huge']));
    }
}
