<?php

namespace LcmtDev\Consent\Frontend;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Consent;
use LcmtDev\Consent\Services\ServiceRegistry;

class Banner
{
    private Settings $settings;
    private ServiceRegistry $registry;
    private Translations $t;

    public function __construct(Settings $settings, ServiceRegistry $registry, Translations $t)
    {
        $this->settings = $settings;
        $this->registry = $registry;
        $this->t = $t;
    }

    public function register(): void
    {
        add_action('wp_footer', [$this, 'renderStyleVars'], 5);
        add_action('wp_footer', [$this, 'render'], 20);
    }

    public function shouldRender(): bool
    {
        if (!$this->settings->get('enabled', true) || is_admin()) {
            return false;
        }
        $services = $this->registry->all();
        if (empty($services)) {
            return false;
        }
        $keys = array_map(fn($s) => $s->key, $services);
        return !Consent::isComplete($keys, $this->settings->effectiveCookieName());
    }

    public function renderStyleVars(): void
    {
        if (!$this->settings->get('enabled', true) || is_admin()) {
            return;
        }
        $a = (array) $this->settings->get('appearance', []);
        $defaults = Settings::defaults()['appearance'];
        $map = [
            '--lcmt-consent-bg' => $a['bg'] ?? $defaults['bg'],
            '--lcmt-consent-text' => $a['text'] ?? $defaults['text'],
            '--lcmt-consent-hover-bg' => $a['hover_bg'] ?? $defaults['hover_bg'],
            '--lcmt-consent-hover-text' => $a['hover_text'] ?? $defaults['hover_text'],
            '--lcmt-consent-border' => $a['border'] ?? $defaults['border'],
            '--lcmt-consent-radius' => ((int) ($a['radius'] ?? $defaults['radius'])) . 'px',
            '--lcmt-consent-z' => (int) ($a['z_index'] ?? $defaults['z_index']),
        ];
        $lines = [];
        foreach ($map as $var => $value) {
            $lines[] = $var . ':' . esc_attr((string) $value);
        }
        $css = ':root{' . implode(';', $lines) . '}';

        $customCss = trim((string) $this->settings->get('custom_css', ''));
        if ($customCss !== '') {
            $css .= "\n" . wp_strip_all_tags($customCss);
        }
        echo "<style id=\"lcmt-consent-vars\">{$css}</style>\n";
    }

    public function render(): void
    {
        if (!$this->shouldRender()) {
            return;
        }
        $position = (string) $this->settings->get('position', 'bottom-left');
        $categories = (array) $this->settings->get('categories', []);
        $services = $this->registry->all();
        $t = $this->t;

        // Group services by category (only those present in categories map)
        $byCategory = [];
        foreach ($services as $s) {
            $byCategory[$s->category][] = $s;
        }

        $privacyUrl = trim((string) $this->settings->get('privacy_url', ''));
        $description = $t->get('texts.description');
        if ($privacyUrl !== '') {
            $description .= ' <a href="' . esc_url($privacyUrl) . '" target="_blank" rel="noopener">' . esc_html__('Learn more', 'lcmt-dev-consent') . '</a>';
        }

        ?>
        <div id="lcmt-consent" class="lcmt-consent lcmt-consent--<?= esc_attr($position) ?>" data-opened="false">
            <div id="lcmt-consent-main" class="lcmt-consent__view" data-opened="true">
                <div class="lcmt-consent__body">
                    <p class="lcmt-consent__title"><?= esc_html($t->get('texts.title')) ?></p>
                    <p class="lcmt-consent__desc"><?= wp_kses_post($description) ?></p>
                </div>
                <div class="lcmt-consent__actions">
                    <button type="button" class="lcmt-consent__btn lcmt-consent__refuse-all"><?= esc_html($t->get('texts.refuse')) ?></button>
                    <button type="button" class="lcmt-consent__btn lcmt-consent__personalize"><?= esc_html($t->get('texts.personalize')) ?></button>
                    <button type="button" class="lcmt-consent__btn lcmt-consent__btn--primary lcmt-consent__accept-all"><?= esc_html($t->get('texts.accept')) ?></button>
                </div>
            </div>
            <div id="lcmt-consent-panel" class="lcmt-consent__view" data-opened="false">
                <div class="lcmt-consent__body">
                    <p class="lcmt-consent__title"><?= esc_html($t->get('texts.panel_title')) ?></p>
                    <div class="lcmt-consent__quick">
                        <button type="button" class="lcmt-consent__chip lcmt-consent__accept-all"><?= esc_html($t->get('texts.all_accept')) ?></button>
                        <button type="button" class="lcmt-consent__chip lcmt-consent__refuse-all"><?= esc_html($t->get('texts.all_refuse')) ?></button>
                    </div>
                    <div class="lcmt-consent__list">
                        <?php foreach ($byCategory as $categoryKey => $list): ?>
                            <?php $cat = $categories[$categoryKey] ?? ['name' => $categoryKey, 'description' => '']; ?>
                            <div class="lcmt-consent__category">
                                <p class="lcmt-consent__cat-name"><?= esc_html($t->getCategory($categoryKey, 'name', $cat['name'] ?? $categoryKey)) ?></p>
                                <p class="lcmt-consent__cat-desc"><?= esc_html($t->getCategory($categoryKey, 'description', $cat['description'] ?? '')) ?></p>
                                <?php foreach ($list as $svc): ?>
                                    <?php
                                    // Run name + description through the translation layer so WP .mo
                                    // (and Polylang/WPML) can localize the built-in English defaults.
                                    $svcName = $t->translatePassthrough($svc->name);
                                    $svcDesc = $svc->description !== '' ? $t->translatePassthrough($svc->description) : '';
                                    ?>
                                    <div class="lcmt-consent__service">
                                        <div class="lcmt-consent__svc-info">
                                            <p class="lcmt-consent__svc-name"><?= esc_html($svcName) ?></p>
                                            <?php if ($svcDesc !== ''): ?>
                                                <p class="lcmt-consent__svc-desc"><?= esc_html($svcDesc) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="lcmt-consent__svc-actions">
                                            <button type="button" data-key="<?= esc_attr($svc->key) ?>" class="lcmt-consent__svc-btn lcmt-consent__svc-accept" aria-selected="false"><?= esc_html($t->get('texts.service_accept')) ?></button>
                                            <button type="button" data-key="<?= esc_attr($svc->key) ?>" class="lcmt-consent__svc-btn lcmt-consent__svc-refuse" aria-selected="false"><?= esc_html($t->get('texts.service_refuse')) ?></button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="lcmt-consent__actions">
                    <button type="button" class="lcmt-consent__btn lcmt-consent__back"><?= esc_html($t->get('texts.back')) ?></button>
                    <button type="button" class="lcmt-consent__btn lcmt-consent__btn--primary lcmt-consent__ok"><?= esc_html($t->get('texts.ok')) ?></button>
                </div>
            </div>
        </div>
        <?php
    }
}
