<?php

namespace LcmtDev\Consent\Admin;

use LcmtDev\Consent\Frontend\CookieTable;
use LcmtDev\Consent\Log\ConsentLog;
use LcmtDev\Consent\Services\CookieRegistry;
use LcmtDev\Consent\Services\ServiceRegistry;

class SettingsPage
{
    private const PAGE_SLUG = 'lcmt-dev-consent';
    private const NONCE_ACTION = 'lcmt_dev_consent_save';

    private Settings $settings;
    private ServiceRegistry $registry;
    private Translations $t;
    private ConsentLog $log;
    private ?CookieRegistry $cookieRegistry = null;

    public function __construct(Settings $settings, ServiceRegistry $registry, Translations $t, ConsentLog $log)
    {
        $this->settings = $settings;
        $this->registry = $registry;
        $this->t = $t;
        $this->log = $log;
    }

    private function cookieRegistry(): CookieRegistry
    {
        if ($this->cookieRegistry === null) {
            $this->cookieRegistry = new CookieRegistry($this->settings, $this->registry);
        }
        return $this->cookieRegistry;
    }

    private function cookieTable(): CookieTable
    {
        return new CookieTable($this->cookieRegistry(), $this->t);
    }

    private function tab_privacy(): void
    {
        $shortcode = '[lcmt_cookies_table]';
        ?>
        <h3><?= esc_html__('Cookie table shortcode', 'lcmt-dev-consent') ?></h3>
        <p class="description"><?= esc_html__('Paste this shortcode into your privacy-policy page to display the table of cookies used by your enabled services.', 'lcmt-dev-consent') ?></p>
        <p>
            <input type="text" class="regular-text code" id="lcmt-shortcode" readonly value="<?= esc_attr($shortcode) ?>" onclick="this.select()">
            <button type="button" class="button" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= esc_js($shortcode) ?>');"><?= esc_html__('Copy', 'lcmt-dev-consent') ?></button>
        </p>
        <p class="description"><?= esc_html__('Hide the essential consent cookie with', 'lcmt-dev-consent') ?> <code>[lcmt_cookies_table essential="0"]</code>.</p>

        <h3 style="margin-top:24px"><?= esc_html__('Live preview', 'lcmt-dev-consent') ?></h3>
        <p class="description"><?= esc_html__('This is what visitors will see for your currently enabled services.', 'lcmt-dev-consent') ?></p>
        <?php
        // Reuse the front-end renderer. Output is escaped internally.
        echo $this->cookieTable()->render([]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
        <hr style="margin:28px 0">
        <h3><?= esc_html__('Let users manage their cookies', 'lcmt-dev-consent') ?></h3>
        <p class="description"><?= esc_html__('RGPD Art. 7(3): users must be able to change or withdraw consent as easily as they gave it. Add one of these anywhere (e.g. a footer menu item) to reopen the preferences panel:', 'lcmt-dev-consent') ?></p>
        <p><strong><?= esc_html__('Shortcode', 'lcmt-dev-consent') ?>:</strong> <code>[lcmt_cookies_settings]</code></p>
        <p><strong><?= esc_html__('HTML link', 'lcmt-dev-consent') ?>:</strong> <code>&lt;a href="#" class="lcmt-open-consent"&gt;<?= esc_html__('Gérer les cookies', 'lcmt-dev-consent') ?>&lt;/a&gt;</code></p>
        <p><strong><?= esc_html__('JavaScript', 'lcmt-dev-consent') ?>:</strong> <code>window.lcmtConsent.open()</code></p>
        <p><strong><?= esc_html__('Menu item', 'lcmt-dev-consent') ?>:</strong> <?= esc_html__('In Appearance → Menus, add a Custom Link with URL', 'lcmt-dev-consent') ?> <code>#cookie-settings</code> <?= esc_html__('and your label.', 'lcmt-dev-consent') ?></p>
        <p><?= esc_html__('Preview:', 'lcmt-dev-consent') ?> <?php echo $this->reopenButtonPreview(); ?></p>
        <?php
    }

    private function reopenButtonPreview(): string
    {
        return '<button type="button" class="button lcmt-open-consent">'
            . esc_html__('Gérer les cookies', 'lcmt-dev-consent')
            . '</button>';
    }

    private function renderCookieEditor(string $serviceKey): void
    {
        $svc = $this->registry->find($serviceKey);
        $rows = $svc ? $this->cookieRegistry()->forService($svc) : [];
        // Fall back to shipped defaults when the service object is not built
        // (e.g. predefined service currently disabled): show its defaults so the
        // admin can pre-edit them.
        if ($svc === null) {
            $defaults = Settings::defaultServiceCookies();
            foreach (($defaults[$serviceKey] ?? []) as $r) {
                $rows[] = CookieRegistry::normalizeRow($r);
            }
        }
        ?>
        <details class="lcmt-cookie-editor">
            <summary><?= esc_html__('Cookies for the privacy-policy table', 'lcmt-dev-consent') ?> (<?= count($rows) ?>)</summary>
            <table class="lcmt-cookie-rows" data-service="<?= esc_attr($serviceKey) ?>">
                <thead><tr>
                    <th><?= esc_html__('Cookie', 'lcmt-dev-consent') ?></th>
                    <th><?= esc_html__('Purpose', 'lcmt-dev-consent') ?></th>
                    <th><?= esc_html__('Retention', 'lcmt-dev-consent') ?></th>
                    <th><?= esc_html__('Issuer', 'lcmt-dev-consent') ?></th>
                    <th><?= esc_html__('3rd-party', 'lcmt-dev-consent') ?></th>
                    <th><?= esc_html__('Policy URL', 'lcmt-dev-consent') ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($rows as $i => $r): ?>
                        <?php $this->cookieRowInputs($serviceKey, (string) $i, $r); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <button type="button" class="button lcmt-add-cookie" data-service="<?= esc_attr($serviceKey) ?>"><?= esc_html__('Add cookie', 'lcmt-dev-consent') ?></button>
            </p>
            <template class="lcmt-cookie-template" data-service="<?= esc_attr($serviceKey) ?>">
                <?php $this->cookieRowInputs($serviceKey, '__INDEX__', ['name' => '', 'purpose' => '', 'retention' => '', 'issuer' => '', 'third_party' => false, 'url' => '']); ?>
            </template>
        </details>
        <?php
    }

    private function cookieRowInputs(string $serviceKey, string $i, array $r): void
    {
        $base = 'lcmt[service_cookies][' . $serviceKey . '][' . $i . ']';
        ?>
        <tr class="lcmt-cookie-row">
            <td><input type="text" name="<?= esc_attr($base) ?>[name]" value="<?= esc_attr($r['name'] ?? '') ?>"></td>
            <td><input type="text" class="regular-text" name="<?= esc_attr($base) ?>[purpose]" value="<?= esc_attr($r['purpose'] ?? '') ?>"></td>
            <td><input type="text" name="<?= esc_attr($base) ?>[retention]" value="<?= esc_attr($r['retention'] ?? '') ?>"></td>
            <td><input type="text" name="<?= esc_attr($base) ?>[issuer]" value="<?= esc_attr($r['issuer'] ?? '') ?>"></td>
            <td style="text-align:center"><input type="checkbox" name="<?= esc_attr($base) ?>[third_party]" <?php checked(!empty($r['third_party'])); ?>></td>
            <td><input type="url" name="<?= esc_attr($base) ?>[url]" value="<?= esc_attr($r['url'] ?? '') ?>"></td>
            <td><button type="button" class="button-link lcmt-remove-cookie" aria-label="<?= esc_attr__('Remove', 'lcmt-dev-consent') ?>">&times;</button></td>
        </tr>
        <?php
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'handleSave']);
        add_action('admin_init', [$this, 'handleExport']);
        add_action('admin_enqueue_scripts', [$this, 'adminAssets']);
    }

    public function addMenu(): void
    {
        add_options_page(
            __('Cookie Consent', 'lcmt-dev-consent'),
            __('Cookie Consent', 'lcmt-dev-consent'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function adminAssets($hook): void
    {
        if ($hook !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_add_inline_script('wp-color-picker', 'jQuery(function($){$(".lcmt-color").wpColorPicker();});');
        wp_add_inline_style('wp-color-picker', '
            .lcmt-tabs{margin:20px 0;border-bottom:1px solid #ccd0d4;padding:0}
            .lcmt-tabs a{padding:10px 16px;display:inline-block;text-decoration:none;border:1px solid transparent;border-bottom:0;background:transparent;color:#555}
            .lcmt-tabs a.active{background:#fff;border-color:#ccd0d4;color:#000;font-weight:600}
            .lcmt-section{background:#fff;padding:20px;border:1px solid #ccd0d4;border-top:0}
            .lcmt-services-table{width:100%;border-collapse:collapse}
            .lcmt-services-table th,.lcmt-services-table td{text-align:left;padding:8px;border-bottom:1px solid #eee;vertical-align:top}
            .lcmt-reset-row{display:flex;align-items:center;gap:10px}
            .lcmt-cookie-editor{margin:6px 0 2px}
            .lcmt-cookie-editor summary{cursor:pointer;color:#2271b1}
            .lcmt-cookie-rows{width:100%;border-collapse:collapse;margin:8px 0}
            .lcmt-cookie-rows th,.lcmt-cookie-rows td{border:1px solid #eee;padding:4px;vertical-align:top}
            .lcmt-cookie-rows input[type=text],.lcmt-cookie-rows input[type=url]{width:100%}
            .lcmt-cookie-editor-row>td{background:#fbfbfb}
        ');

        wp_add_inline_script('wp-color-picker', <<<'JS'
        (function(){
            document.addEventListener('click', function(e){
                var add = e.target.closest('.lcmt-add-cookie');
                if (add) {
                    var key = add.getAttribute('data-service');
                    var tpl = document.querySelector('.lcmt-cookie-template[data-service="'+key+'"]');
                    var tbody = document.querySelector('.lcmt-cookie-rows[data-service="'+key+'"] tbody');
                    if (tpl && tbody) {
                        var idx = 'n' + Date.now();
                        var html = tpl.innerHTML.replace(/__INDEX__/g, idx);
                        var wrap = document.createElement('tbody');
                        wrap.innerHTML = html.trim();
                        tbody.appendChild(wrap.firstChild);
                    }
                    e.preventDefault();
                    return;
                }
                var rm = e.target.closest('.lcmt-remove-cookie');
                if (rm) {
                    var row = rm.closest('.lcmt-cookie-row');
                    if (row) row.remove();
                    e.preventDefault();
                }
            });
        })();
        JS);
    }

    public function handleSave(): void
    {
        if (!isset($_POST['lcmt_dev_consent_save']) || !current_user_can('manage_options')) {
            return;
        }
        check_admin_referer(self::NONCE_ACTION);

        $tab = isset($_POST['lcmt_tab']) ? sanitize_key($_POST['lcmt_tab']) : 'general';
        $notice = 'saved';

        if (!empty($_POST['lcmt_action']) && $_POST['lcmt_action'] === 'reset_consents') {
            $this->settings->bumpConsentVersion();
            $notice = 'reset';
        } else {
            $input = wp_unslash($_POST['lcmt'] ?? []);
            if (!is_array($input)) {
                $input = [];
            }
            $partial = $this->sanitizeForTab($tab, $input);
            $this->settings->save($partial);
        }

        // Post/Redirect/Get with our OWN notice flag. We deliberately avoid
        // add_settings_error()/settings_errors() and the core `settings-updated`
        // param: other plugins (e.g. Yoast's settings-changed listener re-echoes
        // get_settings_errors() globally) and core options-head both re-emit those,
        // producing a duplicate "Settings saved." notice. Rendering our own notice
        // from a private flag keeps it to exactly one and avoids resubmit-on-refresh.
        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE_SLUG, 'tab' => $tab, 'lcmt_notice' => $notice],
            admin_url('options-general.php')
        ));
        exit;
    }

    private function renderNotice(): void
    {
        $notice = isset($_GET['lcmt_notice']) ? sanitize_key(wp_unslash($_GET['lcmt_notice'])) : '';
        $messages = [
            'saved' => __('Settings saved.', 'lcmt-dev-consent'),
            'reset' => __('All user consents have been reset.', 'lcmt-dev-consent'),
        ];
        if (!isset($messages[$notice])) {
            return;
        }
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html($messages[$notice])
        );
    }

    /** Sanitize only the slice of settings that belongs to the current tab. */
    private function sanitizeForTab(string $tab, array $input): array
    {
        switch ($tab) {
            case 'general':
                $out = [
                    'enabled' => !empty($input['enabled']),
                    'position' => in_array($input['position'] ?? '', ['bottom-left', 'bottom-right', 'bottom-center', 'top-center'], true)
                        ? $input['position'] : 'bottom-left',
                    'privacy_url' => isset($input['privacy_url']) ? esc_url_raw($input['privacy_url']) : '',
                    'texts' => [],
                ];
                $texts = $input['texts'] ?? [];
                foreach (Settings::defaults()['texts'] as $k => $default) {
                    $sanitizer = ($k === 'description') ? 'sanitize_textarea_field' : 'sanitize_text_field';
                    $out['texts'][$k] = isset($texts[$k]) ? $sanitizer($texts[$k]) : $default;
                }
                return $out;

            case 'appearance':
                $app = $input['appearance'] ?? [];
                return ['appearance' => [
                    'bg' => $this->sanitizeColor($app['bg'] ?? '', '#ffffff'),
                    'text' => $this->sanitizeColor($app['text'] ?? '', '#000000'),
                    'hover_bg' => $this->sanitizeColor($app['hover_bg'] ?? '', '#ffeb3b'),
                    'hover_text' => $this->sanitizeColor($app['hover_text'] ?? '', '#000000'),
                    'border' => $this->sanitizeColor($app['border'] ?? '', '#000000'),
                    'radius' => max(0, min(50, (int) ($app['radius'] ?? 0))),
                    'z_index' => max(0, (int) ($app['z_index'] ?? 1001)),
                ]];

            case 'services':
                $svcInput = $input['services'] ?? [];
                $services = [];
                foreach (Settings::defaults()['services'] as $key => $default) {
                    $row = $svcInput[$key] ?? [];
                    $services[$key] = [
                        'enabled' => !empty($row['enabled']),
                        'category' => sanitize_key($row['category'] ?? $default['category']),
                        'display_name' => sanitize_text_field($row['display_name'] ?? ''),
                    ];
                    foreach (['id', 'url', 'site_id'] as $field) {
                        if (array_key_exists($field, $default)) {
                            $services[$key][$field] = sanitize_text_field($row[$field] ?? '');
                        }
                    }
                    if (array_key_exists('consent_mode', $default)) {
                        $services[$key]['consent_mode'] = !empty($row['consent_mode']);
                    }
                }
                $cookies = $this->sanitizeServiceCookies((array) ($input['service_cookies'] ?? []));
                return ['services' => $services, 'service_cookies' => $cookies];

            case 'categories':
                $catsInput = $input['categories'] ?? [];
                $cats = [];
                foreach ($catsInput as $catKey => $cat) {
                    $catKey = sanitize_key($catKey);
                    if ($catKey === '') continue;
                    $cats[$catKey] = [
                        'name' => sanitize_text_field($cat['name'] ?? ''),
                        'description' => sanitize_textarea_field($cat['description'] ?? ''),
                    ];
                }
                foreach (Settings::defaults()['categories'] as $k => $d) {
                    if (!isset($cats[$k])) {
                        $cats[$k] = $d;
                    }
                }
                return ['categories' => $cats];

            case 'advanced':
                return [
                    'cookie_name' => sanitize_key($input['cookie_name'] ?? 'cookieConsent') ?: 'cookieConsent',
                    'cookie_lifetime_days' => max(1, min(3650, (int) ($input['cookie_lifetime_days'] ?? 365))),
                    'custom_css' => wp_strip_all_tags((string) ($input['custom_css'] ?? '')),
                ];

            case 'consent_log':
                return [
                    'log_enabled' => !empty($input['log_enabled']),
                    'log_retention_months' => max(1, min(120, (int) ($input['log_retention_months'] ?? 36))),
                ];
        }
        return [];
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $input
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function sanitizeServiceCookies(array $input): array
    {
        $out = [];
        foreach ($input as $serviceKey => $rows) {
            $serviceKey = sanitize_key((string) $serviceKey);
            if ($serviceKey === '' || !is_array($rows)) {
                continue;
            }
            $clean = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = sanitize_text_field((string) ($row['name'] ?? ''));
                if ($name === '') {
                    continue; // drop empty rows
                }
                $url = esc_url_raw((string) ($row['url'] ?? ''));
                $clean[] = [
                    'name' => $name,
                    'purpose' => sanitize_text_field((string) ($row['purpose'] ?? '')),
                    'retention' => sanitize_text_field((string) ($row['retention'] ?? '')),
                    'issuer' => sanitize_text_field((string) ($row['issuer'] ?? '')),
                    'third_party' => !empty($row['third_party']),
                    'url' => $url,
                ];
            }
            if (!empty($clean)) {
                $out[$serviceKey] = $clean;
            }
        }
        return $out;
    }

    private function sanitizeColor(string $value, string $default): string
    {
        $value = trim($value);
        if (preg_match('/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $value)) {
            return $value;
        }
        return $default;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        $tabs = [
            'general' => __('General', 'lcmt-dev-consent'),
            'appearance' => __('Appearance', 'lcmt-dev-consent'),
            'services' => __('Services', 'lcmt-dev-consent'),
            'categories' => __('Categories', 'lcmt-dev-consent'),
            'advanced' => __('Advanced', 'lcmt-dev-consent'),
            'consent_log' => __('Registre de consentement', 'lcmt-dev-consent'),
            'privacy' => __('Politique de confidentialité', 'lcmt-dev-consent'),
        ];
        if (!isset($tabs[$tab])) $tab = 'general';

        $this->renderNotice();
        $url = admin_url('options-general.php?page=' . self::PAGE_SLUG);
        ?>
        <div class="wrap">
            <h1><?= esc_html__('Cookie Consent', 'lcmt-dev-consent') ?></h1>
            <nav class="lcmt-tabs">
                <?php foreach ($tabs as $slug => $label): ?>
                    <a href="<?= esc_url(add_query_arg('tab', $slug, $url)) ?>" class="<?= $tab === $slug ? 'active' : '' ?>"><?= esc_html($label) ?></a>
                <?php endforeach; ?>
            </nav>
            <form method="post">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="lcmt_dev_consent_save" value="1">
                <input type="hidden" name="lcmt_tab" value="<?= esc_attr($tab) ?>">
                <div class="lcmt-section">
                    <?php $this->renderTab($tab); ?>
                </div>
                <p>
                    <?php submit_button(__('Save changes', 'lcmt-dev-consent'), 'primary', 'submit', false); ?>
                </p>
            </form>
        </div>
        <?php
    }

    private function renderTab(string $tab): void
    {
        $method = 'tab_' . str_replace('-', '_', $tab);
        if (method_exists($this, $method)) {
            $this->$method();
        }
    }

    private function tab_general(): void
    {
        $s = $this->settings->all();
        // Show button/label *values* through the translation layer so the admin
        // sees the localized default (e.g. "J'accepte") instead of the English
        // source string — mirrors how Banner::render() displays them to visitors.
        $textVal = fn(string $key): string => $this->t->get('texts.' . $key);
        $positions = [
            'bottom-left' => __('Bottom left', 'lcmt-dev-consent'),
            'bottom-right' => __('Bottom right', 'lcmt-dev-consent'),
            'bottom-center' => __('Bottom center', 'lcmt-dev-consent'),
            'top-center' => __('Top center', 'lcmt-dev-consent'),
        ];
        ?>
        <table class="form-table">
            <tr>
                <th><?= esc_html__('Enable banner', 'lcmt-dev-consent') ?></th>
                <td><label><input type="checkbox" name="lcmt[enabled]" <?php checked(!empty($s['enabled'])); ?>> <?= esc_html__('Show the consent banner to visitors', 'lcmt-dev-consent') ?></label></td>
            </tr>
            <tr>
                <th><?= esc_html__('Position', 'lcmt-dev-consent') ?></th>
                <td>
                    <select name="lcmt[position]">
                        <?php foreach ($positions as $val => $label): ?>
                            <option value="<?= esc_attr($val) ?>" <?php selected($s['position'], $val); ?>><?= esc_html($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?= esc_html__('Banner title', 'lcmt-dev-consent') ?></th>
                <td><input type="text" class="regular-text" name="lcmt[texts][title]" value="<?= esc_attr($textVal('title')) ?>"></td>
            </tr>
            <tr>
                <th><?= esc_html__('Banner description', 'lcmt-dev-consent') ?></th>
                <td><textarea name="lcmt[texts][description]" rows="4" class="large-text"><?= esc_textarea($textVal('description')) ?></textarea></td>
            </tr>
            <tr>
                <th><?= esc_html__('Privacy policy URL', 'lcmt-dev-consent') ?></th>
                <td><input type="url" class="regular-text" name="lcmt[privacy_url]" value="<?= esc_attr($s['privacy_url']) ?>" placeholder="https://…"></td>
            </tr>
            <tr>
                <th colspan="2"><h3><?= esc_html__('Button labels', 'lcmt-dev-consent') ?></h3></th>
            </tr>
            <?php
            $labels = [
                'accept' => __('I accept', 'lcmt-dev-consent'),
                'refuse' => __('I refuse', 'lcmt-dev-consent'),
                'personalize' => __('I personalize', 'lcmt-dev-consent'),
                'back' => __('Back', 'lcmt-dev-consent'),
                'ok' => __('OK', 'lcmt-dev-consent'),
                'service_accept' => __('Per-service accept', 'lcmt-dev-consent'),
                'service_refuse' => __('Per-service refuse', 'lcmt-dev-consent'),
                'all_accept' => __('All accept', 'lcmt-dev-consent'),
                'all_refuse' => __('All refuse', 'lcmt-dev-consent'),
                'panel_title' => __('Panel title', 'lcmt-dev-consent'),
            ];
            foreach ($labels as $k => $label): ?>
                <tr>
                    <th><?= esc_html($label) ?></th>
                    <td><input type="text" class="regular-text" name="lcmt[texts][<?= esc_attr($k) ?>]" value="<?= esc_attr($textVal($k)) ?>"></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php
    }

    private function tab_appearance(): void
    {
        $a = $this->settings->all()['appearance'];
        $colors = [
            'bg' => __('Button background', 'lcmt-dev-consent'),
            'text' => __('Button text', 'lcmt-dev-consent'),
            'hover_bg' => __('Hover / accent background', 'lcmt-dev-consent'),
            'hover_text' => __('Hover / accent text', 'lcmt-dev-consent'),
            'border' => __('Border', 'lcmt-dev-consent'),
        ];
        ?>
        <table class="form-table">
            <?php foreach ($colors as $k => $label): ?>
                <tr>
                    <th><?= esc_html($label) ?></th>
                    <td><input type="text" class="lcmt-color" name="lcmt[appearance][<?= esc_attr($k) ?>]" value="<?= esc_attr($a[$k]) ?>" data-default-color="<?= esc_attr($a[$k]) ?>"></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <th><?= esc_html__('Border radius (px)', 'lcmt-dev-consent') ?></th>
                <td><input type="number" min="0" max="50" name="lcmt[appearance][radius]" value="<?= esc_attr($a['radius']) ?>"></td>
            </tr>
            <tr>
                <th><?= esc_html__('Z-index', 'lcmt-dev-consent') ?></th>
                <td><input type="number" min="0" name="lcmt[appearance][z_index]" value="<?= esc_attr($a['z_index']) ?>"></td>
            </tr>
        </table>
        <?php
    }

    private function tab_services(): void
    {
        $services = $this->settings->all()['services'];
        $meta = $this->settings->predefinedServiceMeta();
        $categories = array_keys($this->settings->all()['categories']);
        ?>
        <h3><?= esc_html__('Predefined services', 'lcmt-dev-consent') ?></h3>
        <table class="lcmt-services-table">
            <thead>
                <tr>
                    <th><?= esc_html__('Service', 'lcmt-dev-consent') ?></th>
                    <th><?= esc_html__('Enabled', 'lcmt-dev-consent') ?></th>
                    <th><?= esc_html__('Configuration', 'lcmt-dev-consent') ?></th>
                    <th><?= esc_html__('Category', 'lcmt-dev-consent') ?></th>
                    <th><?= esc_html__('Display name override', 'lcmt-dev-consent') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services as $key => $row): $m = $meta[$key] ?? []; ?>
                    <tr>
                        <td>
                            <strong><?= esc_html($m['name'] ?? $key) ?></strong><br>
                            <small><a href="<?= esc_url($m['uri'] ?? '#') ?>" target="_blank" rel="noopener"><?= esc_html__('Privacy policy', 'lcmt-dev-consent') ?></a></small>
                        </td>
                        <td><input type="checkbox" name="lcmt[services][<?= esc_attr($key) ?>][enabled]" <?php checked(!empty($row['enabled'])); ?>></td>
                        <td>
                            <?php foreach (($m['id_fields'] ?? []) as $field): ?>
                                <label style="display:block;margin-bottom:4px">
                                    <span style="display:inline-block;min-width:90px"><?= esc_html($field['label']) ?></span>
                                    <input type="text" name="lcmt[services][<?= esc_attr($key) ?>][<?= esc_attr($field['key']) ?>]" value="<?= esc_attr($row[$field['key']] ?? '') ?>" placeholder="<?= esc_attr($field['placeholder'] ?? '') ?>">
                                </label>
                            <?php endforeach; ?>
                            <?php if ($key === 'googletagmanager'): ?>
                                <label style="display:block;margin-top:6px">
                                    <input type="checkbox" name="lcmt[services][<?= esc_attr($key) ?>][consent_mode]" <?php checked(!empty($row['consent_mode'])); ?>>
                                    <?= esc_html__('Use Google Consent Mode v2', 'lcmt-dev-consent') ?>
                                </label>
                                <p class="description" style="margin:4px 0 0"><?= esc_html__('When enabled, GTM loads on every page with all 4 consent signals denied by default. The banner shows 4 individual toggles (analytics_storage, ad_storage, ad_user_data, ad_personalization) instead of one GTM toggle.', 'lcmt-dev-consent') ?></p>
                            <?php endif; ?>
                        </td>
                        <td>
                            <select name="lcmt[services][<?= esc_attr($key) ?>][category]">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= esc_attr($cat) ?>" <?php selected($row['category'], $cat); ?>><?= esc_html($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" name="lcmt[services][<?= esc_attr($key) ?>][display_name]" value="<?= esc_attr($row['display_name'] ?? '') ?>"></td>
                    </tr>
                    <tr class="lcmt-cookie-editor-row">
                        <td colspan="5"><?php $this->renderCookieEditor($key); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php $filterRegistered = $this->registry->filterRegistered(); if (!empty($filterRegistered)): ?>
            <h3 style="margin-top:24px"><?= esc_html__('Services registered via code', 'lcmt-dev-consent') ?></h3>
            <p class="description"><?= esc_html__('Registered through the lcmt_dev_consent_services filter. Edit them in code.', 'lcmt-dev-consent') ?></p>
            <table class="lcmt-services-table">
                <thead>
                    <tr>
                        <th><?= esc_html__('Key', 'lcmt-dev-consent') ?></th>
                        <th><?= esc_html__('Name', 'lcmt-dev-consent') ?></th>
                        <th><?= esc_html__('Category', 'lcmt-dev-consent') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filterRegistered as $svc): ?>
                        <tr>
                            <td><code><?= esc_html($svc->key) ?></code></td>
                            <td><?= esc_html($svc->name) ?></td>
                            <td><?= esc_html($svc->category) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    private function tab_categories(): void
    {
        $cats = $this->settings->all()['categories'];
        ?>
        <p class="description"><?= esc_html__('Default categories can be renamed. Add custom categories for services registered via code.', 'lcmt-dev-consent') ?></p>
        <table class="lcmt-services-table" id="lcmt-categories-table">
            <thead>
                <tr>
                    <th><?= esc_html__('Key', 'lcmt-dev-consent') ?></th>
                    <th><?= esc_html__('Name', 'lcmt-dev-consent') ?></th>
                    <th><?= esc_html__('Description', 'lcmt-dev-consent') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cats as $key => $cat): ?>
                    <tr>
                        <td><code><?= esc_html($key) ?></code></td>
                        <td><input type="text" name="lcmt[categories][<?= esc_attr($key) ?>][name]" value="<?= esc_attr($cat['name'] ?? '') ?>" class="regular-text"></td>
                        <td><textarea name="lcmt[categories][<?= esc_attr($key) ?>][description]" rows="2" class="large-text"><?= esc_textarea($cat['description'] ?? '') ?></textarea></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="3">
                        <strong><?= esc_html__('Add a custom category', 'lcmt-dev-consent') ?></strong><br>
                        <label><?= esc_html__('Key (lowercase, no spaces)', 'lcmt-dev-consent') ?>: <input type="text" name="lcmt_new_cat_key" value="" placeholder="e.g. social"></label>
                        <label style="margin-left:10px"><?= esc_html__('Name', 'lcmt-dev-consent') ?>: <input type="text" name="lcmt_new_cat_name" value=""></label>
                    </td>
                </tr>
            </tbody>
        </table>
        <script>
        (function(){
            document.querySelector('form').addEventListener('submit', function(){
                var k = document.querySelector('[name=lcmt_new_cat_key]');
                var n = document.querySelector('[name=lcmt_new_cat_name]');
                if (k && n && k.value && n.value) {
                    var key = k.value.toLowerCase().replace(/[^a-z0-9_]/g, '');
                    if (!key) return;
                    var form = k.closest('form');
                    var h1 = document.createElement('input');
                    h1.type = 'hidden';
                    h1.name = 'lcmt[categories][' + key + '][name]';
                    h1.value = n.value;
                    form.appendChild(h1);
                    var h2 = document.createElement('input');
                    h2.type = 'hidden';
                    h2.name = 'lcmt[categories][' + key + '][description]';
                    h2.value = '';
                    form.appendChild(h2);
                }
            });
        })();
        </script>
        <?php
    }

    private function tab_advanced(): void
    {
        $s = $this->settings->all();
        ?>
        <table class="form-table">
            <tr>
                <th><?= esc_html__('Cookie name', 'lcmt-dev-consent') ?></th>
                <td><input type="text" name="lcmt[cookie_name]" value="<?= esc_attr($s['cookie_name']) ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><?= esc_html__('Cookie lifetime (days)', 'lcmt-dev-consent') ?></th>
                <td><input type="number" min="1" max="3650" name="lcmt[cookie_lifetime_days]" value="<?= esc_attr($s['cookie_lifetime_days']) ?>"></td>
            </tr>
            <tr>
                <th><?= esc_html__('Reset all user consents', 'lcmt-dev-consent') ?></th>
                <td>
                    <div class="lcmt-reset-row">
                        <button type="submit" name="lcmt_action" value="reset_consents" class="button" onclick="return confirm('<?= esc_js(__('Every visitor will see the banner again. Continue?', 'lcmt-dev-consent')) ?>');"><?= esc_html__('Reset now', 'lcmt-dev-consent') ?></button>
                        <span class="description"><?= esc_html(sprintf(__('Current version: v%d', 'lcmt-dev-consent'), (int) $s['consent_version'])) ?></span>
                    </div>
                </td>
            </tr>
            <tr>
                <th><?= esc_html__('Custom CSS', 'lcmt-dev-consent') ?></th>
                <td>
                    <textarea name="lcmt[custom_css]" rows="8" class="large-text code" spellcheck="false"><?= esc_textarea($s['custom_css']) ?></textarea>
                    <p class="description"><?= esc_html__('Appended after the plugin stylesheet. Use to override any visual detail.', 'lcmt-dev-consent') ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function handleExport(): void
    {
        if (empty($_POST['lcmt_export_log']) || !current_user_can('manage_options')) {
            return;
        }
        check_admin_referer(self::NONCE_ACTION);

        $filters = [
            'consent_id' => sanitize_text_field(wp_unslash($_POST['filter_consent_id'] ?? '')),
            'event' => sanitize_key(wp_unslash($_POST['filter_event'] ?? '')),
            'from' => sanitize_text_field(wp_unslash($_POST['filter_from'] ?? '')),
            'to' => sanitize_text_field(wp_unslash($_POST['filter_to'] ?? '')),
        ];

        // Pull all matching rows (large pages; export is an admin-only action).
        $result = $this->log->query($filters, 1, 500);
        $rows = $result['rows'];
        $page = 2;
        while (count($rows) < $result['total'] && $page <= 200) {
            $more = $this->log->query($filters, $page, 500);
            $rows = array_merge($rows, $more['rows']);
            $page++;
        }

        $csv = $this->log->exportCsv($rows);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="consent-log-' . gmdate('Ymd-His') . '.csv"');
        echo $csv;
        exit;
    }

    private function tab_consent_log(): void
    {
        $s = $this->settings->all();
        $filters = [
            'consent_id' => isset($_GET['filter_consent_id']) ? sanitize_text_field(wp_unslash($_GET['filter_consent_id'])) : '',
            'event' => isset($_GET['filter_event']) ? sanitize_key(wp_unslash($_GET['filter_event'])) : '',
            'from' => isset($_GET['filter_from']) ? sanitize_text_field(wp_unslash($_GET['filter_from'])) : '',
            'to' => isset($_GET['filter_to']) ? sanitize_text_field(wp_unslash($_GET['filter_to'])) : '',
        ];
        $page = max(1, (int) ($_GET['log_page'] ?? 1));
        $perPage = 50;
        $result = $this->log->query($filters, $page, $perPage);
        $totalPages = max(1, (int) ceil($result['total'] / $perPage));
        $events = ['accept_all', 'reject_all', 'custom', 'withdraw'];
        ?>
        <h3><?= esc_html__('Retention', 'lcmt-dev-consent') ?></h3>
        <table class="form-table">
            <tr>
                <th><?= esc_html__('Enable consent logging', 'lcmt-dev-consent') ?></th>
                <td><label><input type="checkbox" name="lcmt[log_enabled]" <?php checked(!empty($s['log_enabled'])); ?>> <?= esc_html__('Record an auditable proof of each consent event', 'lcmt-dev-consent') ?></label></td>
            </tr>
            <tr>
                <th><?= esc_html__('Retention (months)', 'lcmt-dev-consent') ?></th>
                <td>
                    <input type="number" min="1" max="120" name="lcmt[log_retention_months]" value="<?= esc_attr($s['log_retention_months']) ?>">
                    <p class="description"><?= esc_html__('Records older than this are deleted daily. Default 36 months.', 'lcmt-dev-consent') ?></p>
                </td>
            </tr>
        </table>

        <h3 style="margin-top:24px"><?= esc_html__('Records', 'lcmt-dev-consent') ?> (<?= (int) $result['total'] ?>)</h3>
        <?php $base = admin_url('options-general.php?page=' . self::PAGE_SLUG . '&tab=consent_log'); ?>
        <form method="get" style="margin:10px 0">
            <input type="hidden" name="page" value="<?= esc_attr(self::PAGE_SLUG) ?>">
            <input type="hidden" name="tab" value="consent_log">
            <input type="text" name="filter_consent_id" value="<?= esc_attr($filters['consent_id']) ?>" placeholder="<?= esc_attr__('Consent ID', 'lcmt-dev-consent') ?>">
            <select name="filter_event">
                <option value=""><?= esc_html__('All events', 'lcmt-dev-consent') ?></option>
                <?php foreach ($events as $e): ?>
                    <option value="<?= esc_attr($e) ?>" <?php selected($filters['event'], $e); ?>><?= esc_html($e) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="filter_from" value="<?= esc_attr($filters['from']) ?>">
            <input type="date" name="filter_to" value="<?= esc_attr($filters['to']) ?>">
            <?php submit_button(__('Filter', 'lcmt-dev-consent'), 'secondary', '', false); ?>
        </form>

        <table class="lcmt-services-table widefat striped">
            <thead>
                <tr>
                    <th><?= esc_html__('Date (UTC)', 'lcmt-dev-consent') ?></th>
                    <th><?= esc_html__('Event', 'lcmt-dev-consent') ?></th>
                    <th><?= esc_html__('Consent ID', 'lcmt-dev-consent') ?></th>
                    <th><?= esc_html__('Choices', 'lcmt-dev-consent') ?></th>
                    <th><?= esc_html__('Policy', 'lcmt-dev-consent') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($result['rows'])): ?>
                    <tr><td colspan="5"><?= esc_html__('No records.', 'lcmt-dev-consent') ?></td></tr>
                <?php else: foreach ($result['rows'] as $row): ?>
                    <tr>
                        <td><?= esc_html($row['created_at']) ?></td>
                        <td><?= esc_html($row['event']) ?></td>
                        <td><code><?= esc_html($row['consent_id']) ?></code></td>
                        <td><code style="font-size:11px"><?= esc_html($row['choices']) ?></code></td>
                        <td><code><?= esc_html($row['policy_version']) ?></code></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
            <p>
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php $args = array_merge(['log_page' => $p], array_filter([
                        'filter_consent_id' => $filters['consent_id'],
                        'filter_event' => $filters['event'],
                        'filter_from' => $filters['from'],
                        'filter_to' => $filters['to'],
                    ])); ?>
                    <a href="<?= esc_url(add_query_arg($args, $base)) ?>" style="<?= $p === $page ? 'font-weight:700' : '' ?>"><?= (int) $p ?></a>
                <?php endfor; ?>
            </p>
        <?php endif; ?>

        <form method="post" style="margin-top:16px">
            <?php wp_nonce_field(self::NONCE_ACTION); ?>
            <input type="hidden" name="filter_consent_id" value="<?= esc_attr($filters['consent_id']) ?>">
            <input type="hidden" name="filter_event" value="<?= esc_attr($filters['event']) ?>">
            <input type="hidden" name="filter_from" value="<?= esc_attr($filters['from']) ?>">
            <input type="hidden" name="filter_to" value="<?= esc_attr($filters['to']) ?>">
            <button type="submit" name="lcmt_export_log" value="1" class="button button-secondary"><?= esc_html__('Export CSV (current filter)', 'lcmt-dev-consent') ?></button>
        </form>
        <?php
    }
}
