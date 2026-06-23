<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Frontend\ConsentReopen;
use LcmtDev\Consent\Services\ServiceRegistry;

class PanelRouteTest extends TestCase
{
    public function test_panel_returns_markup_html(): void
    {
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

        $settings = new Settings();
        $reopen = new ConsentReopen($settings, new ServiceRegistry($settings), new Translations($settings));

        $result = $reopen->panel();
        $this->assertArrayHasKey('html', $result);
        $this->assertStringContainsString('id="lcmt-consent-panel"', $result['html']);
    }
}
