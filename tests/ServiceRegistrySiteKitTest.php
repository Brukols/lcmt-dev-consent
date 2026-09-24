<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Integrations\SiteKit;
use LcmtDev\Consent\Services\ServiceRegistry;

class ServiceRegistrySiteKitTest extends TestCase
{
    private const SIGNAL_KEYS = [
        'google_analytics_storage',
        'google_ad_storage',
        'google_ad_user_data',
        'google_ad_personalization',
    ];

    private function registry(array $consentSettings = [], bool $siteKitActive = true): ServiceRegistry
    {
        $options = [
            Settings::OPTION_KEY => $consentSettings,
            SiteKit::ANALYTICS_OPTION => ['useSnippet' => true, 'googleTagID' => 'GT-XYZ789'],
            SiteKit::ACTIVE_MODULES_OPTION => ['analytics-4'],
        ];
        Functions\when('get_option')->alias(fn($name, $default = false) => $options[$name] ?? $default);
        Functions\when('apply_filters')->returnArg(2);

        $settings = new Settings();
        return new ServiceRegistry($settings, new SiteKit($settings, fn() => $siteKitActive));
    }

    public function test_site_kit_turns_consent_mode_on_without_gtm(): void
    {
        $registry = $this->registry();

        $this->assertTrue($registry->isConsentMode());
        $this->assertFalse($registry->isGtmConsentMode());
        $this->assertSame(self::SIGNAL_KEYS, $registry->keys());
    }

    public function test_no_signal_services_without_site_kit_or_gtm(): void
    {
        $registry = $this->registry([], false);

        $this->assertFalse($registry->isConsentMode());
        $this->assertSame([], $registry->keys());
    }

    public function test_basic_mode_hands_the_tag_id_to_the_signal_injectors(): void
    {
        $service = $this->registry()->find('google_analytics_storage');

        $this->assertSame('analytics_storage', $service->data['signal']);
        $this->assertSame('GT-XYZ789', $service->data['sitekit_id']);
    }

    public function test_advanced_mode_does_not_load_the_tag_client_side(): void
    {
        $service = $this->registry(['sitekit_advanced' => true])->find('google_analytics_storage');

        $this->assertArrayNotHasKey('sitekit_id', $service->data);
    }

    public function test_google_analytics_is_ignored_whatever_its_id(): void
    {
        foreach (['GT-XYZ789', 'G-OTHER1'] as $id) {
            $registry = $this->registry(['services' => ['googleanalytics' => ['enabled' => true, 'id' => $id]]]);
            $this->assertNull($registry->find('googleanalytics'), $id);
        }
    }

    public function test_google_tag_manager_is_ignored(): void
    {
        $registry = $this->registry(['services' => ['googletagmanager' => ['enabled' => true, 'id' => 'GTM-ABC']]]);

        $this->assertNull($registry->find('googletagmanager'));
    }

    public function test_gtm_consent_mode_is_off_when_site_kit_handles_the_tags(): void
    {
        $registry = $this->registry(['services' => ['googletagmanager' => ['enabled' => true, 'id' => 'GTM-ABC', 'consent_mode' => true]]]);

        $this->assertFalse($registry->isGtmConsentMode());
        $this->assertTrue($registry->isConsentMode());
    }

    public function test_google_services_come_back_without_site_kit(): void
    {
        $registry = $this->registry([
            'services' => [
                'googleanalytics' => ['enabled' => true, 'id' => 'G-OTHER1'],
                'googletagmanager' => ['enabled' => true, 'id' => 'GTM-ABC'],
            ],
        ], false);

        $this->assertNotNull($registry->find('googleanalytics'));
        $this->assertNotNull($registry->find('googletagmanager'));
    }

    public function test_managed_service_keys(): void
    {
        $this->assertSame(['googleanalytics', 'googletagmanager'], $this->registry()->siteKitManagedServices());
        $this->assertSame([], $this->registry([], false)->siteKitManagedServices());
    }
}
