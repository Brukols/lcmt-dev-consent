<?php

namespace LcmtDev\Consent;

use LcmtDev\Consent\Admin\SettingsPage;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Frontend\Assets;
use LcmtDev\Consent\Frontend\Banner;
use LcmtDev\Consent\Frontend\ScriptInjector;
use LcmtDev\Consent\Frontend\YouTubeEmbed;
use LcmtDev\Consent\Log\ConsentLog;
use LcmtDev\Consent\Log\RestController;
use LcmtDev\Consent\Services\ServiceRegistry;

class Plugin
{
    public function boot(): void
    {
        $settings = new Settings();
        $registry = new ServiceRegistry($settings);
        $translations = new Translations($settings);
        $translations->register();

        $log = new ConsentLog($settings);

        if (is_admin()) {
            (new SettingsPage($settings, $registry, $translations, $log))->register();
            add_action('admin_init', [$log, 'maybeUpgrade']);
        }

        (new RestController($settings, $registry, $log))->register();

        add_action('lcmt_dev_consent_purge', function () use ($settings, $log) {
            $log->purgeOlderThan((int) $settings->get('log_retention_months', 36));
        });
        add_action('init', function () {
            if (!wp_next_scheduled('lcmt_dev_consent_purge')) {
                wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'lcmt_dev_consent_purge');
            }
        });

        (new Assets($settings, $registry, $translations))->register();
        (new Banner($settings, $registry, $translations))->register();
        (new ScriptInjector($settings, $registry))->register();
        (new YouTubeEmbed())->register();
    }
}
