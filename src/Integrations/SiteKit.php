<?php

namespace LcmtDev\Consent\Integrations;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Consent;

/**
 * Google Site Kit compatibility.
 *
 * Site Kit prints its Google tag on every page, whatever the visitor chose.
 * When it does, this integration puts the tag under Google Consent Mode v2:
 * the four consent signals become banner toggles and their state is sent to
 * gtag before Site Kit's tag runs (see ScriptInjector).
 *
 * - Basic mode (default): Site Kit's tag is blocked until at least one
 *   signal is granted, so nothing reaches Google before consent. On the first
 *   accept the banner loads the tag itself (see injectors.ts); on later pages
 *   Site Kit prints it again, untouched.
 * - Advanced mode (`sitekit_advanced`): Site Kit's tag loads before any
 *   choice, with every signal denied by default, so Google gets cookieless
 *   pings for modelling. Requires the client's informed choice (CNIL). Once
 *   the visitor explicitly refuses every signal, the tag is blocked as in
 *   basic mode: nothing is sent against a stated refusal.
 *
 * Nothing is changed in Site Kit's own settings: the tag is only blocked
 * through Site Kit's `googlesitekit_{module}_tag_blocked` filters, so
 * deactivating this plugin restores Site Kit's default behaviour.
 */
class SiteKit
{
    public const ANALYTICS_OPTION = 'googlesitekit_analytics-4_settings';
    public const ACTIVE_MODULES_OPTION = 'googlesitekit_active_modules';

    /** Site Kit modules whose tag must wait for consent. */
    public const BLOCKABLE_MODULES = ['analytics-4', 'ads', 'tagmanager'];

    private Settings $settings;
    /** @var callable(): bool */
    private $isPluginActive;
    private ?bool $detected = null;

    /**
     * @param callable|null $isPluginActive Overridable for tests; defaults to
     *                                      checking Site Kit's version constant.
     */
    public function __construct(Settings $settings, ?callable $isPluginActive = null)
    {
        $this->settings = $settings;
        $this->isPluginActive = $isPluginActive ?? fn() => defined('GOOGLESITEKIT_VERSION');
    }

    public function register(): void
    {
        if (!$this->isDetected()) {
            return;
        }
        foreach (self::BLOCKABLE_MODULES as $module) {
            add_filter("googlesitekit_{$module}_tag_blocked", [$this, 'filterTagBlocked']);
        }
    }

    /**
     * Whether Site Kit is active and prints a Google tag on the front end.
     */
    public function isDetected(): bool
    {
        if ($this->detected === null) {
            $modules = (array) get_option(self::ACTIVE_MODULES_OPTION, []);
            $analytics = $this->analyticsSettings();
            $this->detected = (bool) call_user_func($this->isPluginActive)
                && in_array('analytics-4', $modules, true)
                && !empty($analytics['useSnippet'])
                && $this->tagId() !== '';
        }
        return $this->detected;
    }

    /**
     * The ID Site Kit loads gtag.js with: the Google tag ID (GT-…) when Site
     * Kit found one, the GA4 measurement ID (G-…) otherwise.
     */
    public function tagId(): string
    {
        $analytics = $this->analyticsSettings();
        foreach (['googleTagID', 'measurementID'] as $key) {
            $id = (string) ($analytics[$key] ?? '');
            if (preg_match('/^(G|GT|AW)-[A-Za-z0-9]+$/', $id)) {
                return $id;
            }
        }
        return '';
    }

    public function isAdvancedMode(): bool
    {
        return (bool) $this->settings->get('sitekit_advanced', false);
    }

    /**
     * Callback of the `googlesitekit_{module}_tag_blocked` filters.
     *
     * @param bool $blocked
     */
    public function filterTagBlocked($blocked): bool
    {
        if ($blocked) {
            return true;
        }
        if ($this->isAdvancedMode()) {
            return $this->hasRefusedEverySignal();
        }
        return !$this->hasGrantedSignal();
    }

    /**
     * Whether the visitor granted at least one Consent Mode signal.
     */
    public function hasGrantedSignal(): bool
    {
        $cookies = Consent::getCookies($this->settings->effectiveCookieName());
        foreach (array_keys($this->settings->consentModeServices()) as $key) {
            if (($cookies[$key] ?? null) === 'true') {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the visitor explicitly refused every Consent Mode signal (not
     * merely "not decided yet").
     */
    public function hasRefusedEverySignal(): bool
    {
        $cookies = Consent::getCookies($this->settings->effectiveCookieName());
        foreach (array_keys($this->settings->consentModeServices()) as $key) {
            if (($cookies[$key] ?? null) !== 'false') {
                return false;
            }
        }
        return true;
    }

    private function analyticsSettings(): array
    {
        $analytics = get_option(self::ANALYTICS_OPTION, []);
        return is_array($analytics) ? $analytics : [];
    }
}
