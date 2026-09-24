<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Integrations\SiteKit;

class SiteKitTest extends TestCase
{
    protected function tearDown(): void
    {
        $_COOKIE = [];
        parent::tearDown();
    }

    /**
     * @param array $options option name => value; anything else returns the default
     */
    private function stubOptions(array $options): void
    {
        Functions\when('get_option')->alias(fn($name, $default = false) => $options[$name] ?? $default);
    }

    private function siteKitOptions(array $analytics = [], array $modules = ['analytics-4'], array $consent = []): array
    {
        return [
            SiteKit::ANALYTICS_OPTION => $analytics + ['useSnippet' => true, 'measurementID' => 'G-ABC123', 'googleTagID' => 'GT-XYZ789'],
            SiteKit::ACTIVE_MODULES_OPTION => $modules,
            Settings::OPTION_KEY => $consent,
        ];
    }

    private function make(bool $pluginActive = true): SiteKit
    {
        return new SiteKit(new Settings(), fn() => $pluginActive);
    }

    public function test_detected_when_site_kit_prints_the_analytics_tag(): void
    {
        $this->stubOptions($this->siteKitOptions());
        $this->assertTrue($this->make()->isDetected());
    }

    public function test_not_detected_when_plugin_inactive(): void
    {
        $this->stubOptions($this->siteKitOptions());
        $this->assertFalse($this->make(false)->isDetected());
    }

    public function test_not_detected_when_analytics_module_inactive(): void
    {
        $this->stubOptions($this->siteKitOptions([], ['search-console']));
        $this->assertFalse($this->make()->isDetected());
    }

    public function test_not_detected_when_snippet_disabled(): void
    {
        $this->stubOptions($this->siteKitOptions(['useSnippet' => false]));
        $this->assertFalse($this->make()->isDetected());
    }

    public function test_not_detected_without_tag_id(): void
    {
        $this->stubOptions($this->siteKitOptions(['measurementID' => '', 'googleTagID' => '']));
        $this->assertFalse($this->make()->isDetected());
    }

    public function test_tag_id_prefers_google_tag_id(): void
    {
        $this->stubOptions($this->siteKitOptions());
        $this->assertSame('GT-XYZ789', $this->make()->tagId());
    }

    public function test_tag_id_falls_back_to_measurement_id(): void
    {
        $this->stubOptions($this->siteKitOptions(['googleTagID' => '']));
        $this->assertSame('G-ABC123', $this->make()->tagId());
    }

    public function test_basic_mode_blocks_tag_without_consent(): void
    {
        $this->stubOptions($this->siteKitOptions());
        $this->assertTrue($this->make()->filterTagBlocked(false));
    }

    public function test_basic_mode_blocks_tag_when_every_signal_refused(): void
    {
        $this->stubOptions($this->siteKitOptions());
        $_COOKIE['cookieConsent'] = '!google_analytics_storage=false!google_ad_storage=false';
        $this->assertTrue($this->make()->filterTagBlocked(false));
    }

    public function test_basic_mode_lets_tag_through_once_a_signal_is_granted(): void
    {
        $this->stubOptions($this->siteKitOptions());
        $_COOKIE['cookieConsent'] = '!google_analytics_storage=true!google_ad_storage=false';
        $this->assertFalse($this->make()->filterTagBlocked(false));
    }

    public function test_advanced_mode_never_blocks_tag(): void
    {
        $this->stubOptions($this->siteKitOptions([], ['analytics-4'], ['sitekit_advanced' => true]));
        $this->assertFalse($this->make()->filterTagBlocked(false));
    }

    public function test_keeps_a_block_decided_by_someone_else(): void
    {
        $this->stubOptions($this->siteKitOptions([], ['analytics-4'], ['sitekit_advanced' => true]));
        $this->assertTrue($this->make()->filterTagBlocked(true));
    }

    public function test_register_hooks_every_blockable_module(): void
    {
        $this->stubOptions($this->siteKitOptions());
        $hooked = [];
        Functions\when('add_filter')->alias(function ($hook) use (&$hooked) {
            $hooked[] = $hook;
        });

        $this->make()->register();

        $this->assertSame([
            'googlesitekit_analytics-4_tag_blocked',
            'googlesitekit_ads_tag_blocked',
            'googlesitekit_tagmanager_tag_blocked',
        ], $hooked);
    }

    public function test_register_does_nothing_when_not_detected(): void
    {
        $this->stubOptions($this->siteKitOptions());
        Functions\expect('add_filter')->never();
        $this->make(false)->register();
        $this->addToAssertionCount(1);
    }
}
