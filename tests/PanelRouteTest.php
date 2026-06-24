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

    private function stubCommonFns(): void
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
    }

    /** Minimal stand-in for WP_REST_Request carrying one query param. */
    private function request(?string $lang)
    {
        return new class ($lang) {
            private ?string $lang;
            public function __construct(?string $lang)
            {
                $this->lang = $lang;
            }
            public function get_param(string $key)
            {
                return $key === 'lcmt_lang' ? $this->lang : null;
            }
        };
    }

    private function reopen(): ConsentReopen
    {
        $settings = new Settings();
        return new ConsentReopen($settings, new ServiceRegistry($settings), new Translations($settings));
    }

    public function test_panel_switches_locale_when_lang_param_differs_from_current(): void
    {
        $this->stubCommonFns();
        Functions\when('determine_locale')->justReturn('fr_FR');
        Functions\expect('switch_to_locale')->once()->with('en_US')->andReturn(true);
        Functions\expect('restore_previous_locale')->once();

        $result = $this->reopen()->panel($this->request('en_US'));
        $this->assertStringContainsString('id="lcmt-consent-panel"', $result['html']);
    }

    public function test_panel_does_not_switch_when_lang_matches_current(): void
    {
        $this->stubCommonFns();
        Functions\when('determine_locale')->justReturn('en_US');
        Functions\expect('switch_to_locale')->never();
        Functions\expect('restore_previous_locale')->never();

        $result = $this->reopen()->panel($this->request('en_US'));
        $this->assertArrayHasKey('html', $result);
    }

    public function test_panel_ignores_malformed_locale(): void
    {
        $this->stubCommonFns();
        Functions\expect('switch_to_locale')->never();

        $result = $this->reopen()->panel($this->request('en_US; rm -rf /'));
        $this->assertArrayHasKey('html', $result);
    }

    public function test_panel_without_request_does_not_switch(): void
    {
        $this->stubCommonFns();
        Functions\expect('switch_to_locale')->never();

        $result = $this->reopen()->panel();
        $this->assertArrayHasKey('html', $result);
    }
}
