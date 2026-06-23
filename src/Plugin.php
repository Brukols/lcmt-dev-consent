<?php

namespace LcmtDev\Consent;

use LcmtDev\Consent\Admin\SettingsPage;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Frontend\Assets;
use LcmtDev\Consent\Frontend\Banner;
use LcmtDev\Consent\Frontend\ScriptInjector;
use LcmtDev\Consent\Frontend\YouTubeEmbed;
use LcmtDev\Consent\Services\ServiceRegistry;

class Plugin
{
    public function boot(): void
    {
        $settings = new Settings();
        $registry = new ServiceRegistry($settings);
        $translations = new Translations($settings);
        $translations->register();

        if (is_admin()) {
            (new SettingsPage($settings, $registry, $translations))->register();
        }

        (new Assets($settings, $registry, $translations))->register();
        (new Banner($settings, $registry, $translations))->register();
        (new ScriptInjector($settings, $registry))->register();
        (new YouTubeEmbed())->register();
    }
}
