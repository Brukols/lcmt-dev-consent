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
            '--lcmt-consent-shadow' => Settings::shadowPresets()[$a['shadow'] ?? $defaults['shadow']]
                ?? Settings::shadowPresets()[$defaults['shadow']],
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
        echo $this->buildHtml();
    }

    public function buildHtml(): string
    {
        $position = (string) $this->settings->get('position', 'bottom-left');
        $categories = (array) $this->settings->get('categories', []);
        $services = $this->registry->all();
        $t = $this->t;
        $titleIcon = $this->titleIconHtml();

        // Group services by category (only those present in categories map)
        $byCategory = [];
        foreach ($services as $s) {
            $byCategory[$s->category][] = $s;
        }

        $privacyUrl = $this->t->localizeUrl((string) $this->settings->get('privacy_url', ''));
        $description = $t->get('texts.description');
        if ($privacyUrl !== '') {
            $description .= ' <a href="' . esc_url($privacyUrl) . '" target="_blank" rel="noopener">' . esc_html__('Learn more', 'lcmt-dev-consent') . '</a>';
        }

        ob_start();
        ?>
        <div id="lcmt-consent" class="lcmt-consent lcmt-consent--<?= esc_attr($position) ?>" data-opened="false">
            <div id="lcmt-consent-main" class="lcmt-consent__view" data-opened="true">
                <div class="lcmt-consent__body">
                    <p class="lcmt-consent__title"><?= $titleIcon ?><?= esc_html($t->get('texts.title')) ?></p>
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
                    <p class="lcmt-consent__title"><?= $titleIcon ?><?= esc_html($t->get('texts.panel_title')) ?></p>
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
        return (string) ob_get_clean();
    }

    /**
     * Optional cookie indicator rendered before the banner + panel titles so
     * visitors immediately recognize the consent panel. Controlled by the
     * `appearance.title_icon` setting: 'icon' (inline SVG, default), 'emoji'
     * (🍪), or 'none'. Always aria-hidden — the title text already conveys it.
     */
    private function titleIconHtml(): string
    {
        $appearance = $this->settings->all()['appearance'] ?? [];
        $mode = (string) ($appearance['title_icon'] ?? 'icon');
        if ($mode === 'none') {
            return '';
        }
        if ($mode === 'emoji') {
            return '<span class="lcmt-consent__title-icon" aria-hidden="true">🍪</span>';
        }
        if ($mode === 'custom') {
            $url = (string) ($appearance['title_icon_url'] ?? '');
            if ($url !== '') {
                return '<img class="lcmt-consent__title-icon" src="' . esc_url($url) . '" alt="" aria-hidden="true">';
            }
            // 'custom' selected but no image chosen yet → fall back to the SVG.
        }
        // Default: an inline cookie SVG. currentColor + 1em sizing so it follows
        // the title's color and font-size with no extra assets to load.
        return '<svg class="lcmt-consent__title-icon" aria-hidden="true" focusable="false" '
            . 'width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor">'
            . '<path d="M21.95 10.99a1 1 0 0 0-.86-.99 2.5 2.5 0 0 1-2.09-2.09 1 1 0 0 0-.99-.86 2.5 2.5 0 0 1-2.45-3.01A1 1 0 0 0 12 2a10 10 0 1 0 9.95 8.99ZM8 9a1 1 0 1 1 0-2 1 1 0 0 1 0 2Zm1 6a1 1 0 1 1 0-2 1 1 0 0 1 0 2Zm4 3a1 1 0 1 1 0-2 1 1 0 0 1 0 2Zm2.5-5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z"/>'
            . '</svg>';
    }
}
