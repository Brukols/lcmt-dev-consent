# Reopen Cookie Preferences ("Gérer les cookies") Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users reopen the granular cookie-preferences panel on any page after a decision — via a `.lcmt-open-consent` class, a `window.lcmtConsent.open()` API, and a `[lcmt_cookies_settings]` shortcode — without loading the banner bundle until they actually click.

**Architecture:** For decided users, `Assets` no longer enqueues the banner; a new `ConsentReopen` outputs a tiny inline opener script + the client config + a REST panel URL. On first trigger the opener fetches the panel markup from `GET /v1/panel` (reusing `Banner::buildHtml()`), injects the CSS+JS bundle, and the booted banner opens straight to the panel. The client-config builder is factored into a shared `ClientConfig` helper used by both `Assets` and `ConsentReopen`.

**Tech Stack:** PHP 7.4+ WordPress plugin (inline PSR-4-ish autoload under `LcmtDev\Consent\`), WP REST + Shortcode API, TypeScript/SCSS (webpack — bundle rebuild required for this feature). Tests: PHPUnit 9 + Brain\Monkey (under `tests/`).

## Global Constraints

- **PHP floor:** 7.4. **Namespace:** `LcmtDev\Consent\…` (autoloader maps to `src/`).
- **Settings:** single autoloaded option via `Settings::all()/get()`; cookie name always via `Settings::effectiveCookieName()`.
- **Performance promise:** decided users must NOT load the banner CSS/JS until they trigger reopen. Decided pages may carry only a small inline script + JSON config (no external JS/CSS).
- **Panel markup single source of truth:** PHP (`Banner::buildHtml()`); never duplicate banner markup in TS.
- **REST:** panel route is public read-only markup (no user data, no nonce). Namespace `lcmt-dev-consent/v1` (reuse `RestController::NAMESPACE`).
- **i18n:** user-facing strings via `__()`/`esc_html__()` (text domain `lcmt-dev-consent`); French in `languages/lcmt-dev-consent-fr_FR.po` recompiled with `msgfmt`.
- **Escaping:** all dynamic output escaped; inline config emitted with `wp_json_encode`.
- **Tests:** `php -d memory_limit=512M vendor/bin/phpunit` from the plugin root (env sets a bogus `memory_limit=2`).
- **Build:** after `assets/src/*` changes run `npm run build`; commit regenerated `assets/dist/*` + `manifest.json`. Type-check with `npx tsc --noEmit`.
- **Docs:** update `.claude/*.md` in the same change.

---

## File Structure

**Create:**
- `src/Frontend/ClientConfig.php` — shared `build(Settings,ServiceRegistry): array`.
- `src/Frontend/ConsentReopen.php` — decided-state opener + config, `[lcmt_cookies_settings]` shortcode, `GET /v1/panel` route.
- `tests/ClientConfigBuilderTest.php`, `tests/PanelRouteTest.php`, `tests/ConsentReopenTest.php`, `tests/BannerBuildHtmlTest.php`.

**Modify:**
- `src/Frontend/Banner.php` — extract `buildHtml(): string`.
- `src/Frontend/Assets.php` — delegate `clientConfig()` to `ClientConfig::build()`.
- `assets/src/types.ts` — extend `LcmtConsentConfig` with `open?`.
- `assets/src/banner.ts` — `open()`/`openPanel()` + lazy-boot open.
- `src/Plugin.php` — wire `ConsentReopen`.
- `src/Admin/SettingsPage.php` — reopen help section in `tab_privacy()`.
- `languages/lcmt-dev-consent-fr_FR.po` (+ `.mo`); `.claude/frontend.md`, `.claude/extending.md`, `.claude/admin-settings.md`.

---

## Task 1: Extract `Banner::buildHtml()`

**Files:**
- Modify: `src/Frontend/Banner.php`
- Test: `tests/BannerBuildHtmlTest.php`

**Interfaces:**
- Produces: `Banner::buildHtml(): string` — returns the full banner markup (both `#lcmt-consent-main` and `#lcmt-consent-panel` views) as a string, independent of consent state. `render()` echoes it only when `shouldRender()`.

- [ ] **Step 1: Write the failing test**

`tests/BannerBuildHtmlTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Frontend\Banner;
use LcmtDev\Consent\Services\ServiceRegistry;

class BannerBuildHtmlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

    public function test_build_html_contains_both_views(): void
    {
        $settings = new Settings();
        $registry = new ServiceRegistry($settings);
        $banner = new Banner($settings, $registry, new Translations($settings));

        $html = $banner->buildHtml();
        $this->assertStringContainsString('id="lcmt-consent"', $html);
        $this->assertStringContainsString('id="lcmt-consent-main"', $html);
        $this->assertStringContainsString('id="lcmt-consent-panel"', $html);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter BannerBuildHtmlTest`
Expected: FAIL — `buildHtml()` undefined.

- [ ] **Step 3: Refactor `render()` into `buildHtml()` + echo**

In `src/Frontend/Banner.php`, replace the `render()` method with the following two methods. `buildHtml()` is the **current** body of `render()` (everything after the `shouldRender()` guard), wrapped in output buffering and `return`ed; `render()` keeps the guard and echoes.

```php
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

        $byCategory = [];
        foreach ($services as $s) {
            $byCategory[$s->category][] = $s;
        }

        $privacyUrl = trim((string) $this->settings->get('privacy_url', ''));
        $description = $t->get('texts.description');
        if ($privacyUrl !== '') {
            $description .= ' <a href="' . esc_url($privacyUrl) . '" target="_blank" rel="noopener">' . esc_html__('Learn more', 'lcmt-dev-consent') . '</a>';
        }

        ob_start();
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
```

- [ ] **Step 4: Run to verify it passes**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter BannerBuildHtmlTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Frontend/Banner.php tests/BannerBuildHtmlTest.php
git commit -m "♻️ Banner: extract buildHtml() markup builder"
```

---

## Task 2: Shared `ClientConfig` builder

**Files:**
- Create: `src/Frontend/ClientConfig.php`
- Modify: `src/Frontend/Assets.php` (`clientConfig()` delegates)
- Test: `tests/ClientConfigBuilderTest.php`

**Interfaces:**
- Produces: `ClientConfig::build(Settings $settings, ServiceRegistry $registry): array` — the exact array `Assets::clientConfig()` used to return (`cookieName`, `cookieLifetimeDays`, `services`, `categories`, `texts`, `log`).
- `Assets::clientConfig()` now returns `ClientConfig::build($this->settings, $this->registry)`.

- [ ] **Step 1: Write the failing test**

`tests/ClientConfigBuilderTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Frontend\ClientConfig;
use LcmtDev\Consent\Services\ServiceRegistry;

class ClientConfigBuilderTest extends TestCase
{
    public function test_build_returns_expected_keys(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('rest_url')->alias(fn($p) => 'https://example.test/wp-json/' . ltrim($p, '/'));
        Functions\when('wp_create_nonce')->justReturn('nonce123');

        $settings = new Settings();
        $config = ClientConfig::build($settings, new ServiceRegistry($settings));

        foreach (['cookieName', 'cookieLifetimeDays', 'services', 'categories', 'texts', 'log'] as $k) {
            $this->assertArrayHasKey($k, $config);
        }
        $this->assertSame('https://example.test/wp-json/lcmt-dev-consent/v1/log', $config['log']['endpoint']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter ClientConfigBuilderTest`
Expected: FAIL — class `ClientConfig` not found.

- [ ] **Step 3: Create `ClientConfig`**

`src/Frontend/ClientConfig.php`:
```php
<?php

namespace LcmtDev\Consent\Frontend;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Log\RestController;
use LcmtDev\Consent\Services\ServiceRegistry;

class ClientConfig
{
    public static function build(Settings $settings, ServiceRegistry $registry): array
    {
        $services = array_map(fn($s) => $s->toClientConfig(), $registry->all());

        return [
            'cookieName' => $settings->effectiveCookieName(),
            'cookieLifetimeDays' => (int) $settings->get('cookie_lifetime_days', 365),
            'services' => array_values($services),
            'categories' => (array) $settings->get('categories', []),
            'texts' => (array) $settings->get('texts', []),
            'log' => [
                'enabled' => (bool) $settings->get('log_enabled', true),
                'endpoint' => rest_url(RestController::NAMESPACE . RestController::ROUTE),
                'nonce' => wp_create_nonce('wp_rest'),
            ],
        ];
    }
}
```

- [ ] **Step 4: Delegate `Assets::clientConfig()`**

In `src/Frontend/Assets.php`, replace the body of `clientConfig()` with:
```php
    private function clientConfig(): array
    {
        return \LcmtDev\Consent\Frontend\ClientConfig::build($this->settings, $this->registry);
    }
```

- [ ] **Step 5: Run to verify it passes (and existing config test still green)**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter ClientConfigBuilderTest`
Expected: PASS.
Run: `php -d memory_limit=512M vendor/bin/phpunit --filter ClientConfigTest`
Expected: PASS (the existing Assets test still works through the delegation).

- [ ] **Step 6: Commit**

```bash
git add src/Frontend/ClientConfig.php src/Frontend/Assets.php tests/ClientConfigBuilderTest.php
git commit -m "♻️ Factor client config into shared ClientConfig builder"
```

---

## Task 3: Panel REST route + `ConsentReopen` skeleton

**Files:**
- Create: `src/Frontend/ConsentReopen.php`
- Test: `tests/PanelRouteTest.php`

**Interfaces:**
- Consumes: `Banner::buildHtml()`, `Settings`, `ServiceRegistry`, `Translations`.
- Produces:
  - `new ConsentReopen(Settings $settings, ServiceRegistry $registry, Translations $t)`.
  - `register(): void` — hooks `rest_api_init` → `registerRoute()`, `init` → `add_shortcode`, `wp_footer` → `maybeOutputOpener` (added in Task 4).
  - `registerRoute(): void` — `GET lcmt-dev-consent/v1/panel`, public, callback `panel()`.
  - `panel(): array` — returns `['html' => Banner::buildHtml()]`.

- [ ] **Step 1: Write the failing test**

`tests/PanelRouteTest.php`:
```php
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
```

- [ ] **Step 2: Run to verify it fails**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter PanelRouteTest`
Expected: FAIL — class `ConsentReopen` not found.

- [ ] **Step 3: Create `ConsentReopen` with route + panel**

`src/Frontend/ConsentReopen.php`:
```php
<?php

namespace LcmtDev\Consent\Frontend;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Log\RestController;
use LcmtDev\Consent\Services\ServiceRegistry;

class ConsentReopen
{
    public const PANEL_ROUTE = '/panel';

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
        add_action('rest_api_init', [$this, 'registerRoute']);
        add_action('init', function () {
            add_shortcode('lcmt_cookies_settings', [$this, 'shortcode']);
        });
        add_action('wp_footer', [$this, 'maybeOutputOpener'], 30);
    }

    public function registerRoute(): void
    {
        register_rest_route(RestController::NAMESPACE, self::PANEL_ROUTE, [
            'methods' => 'GET',
            'callback' => [$this, 'panel'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** @return array{html:string} */
    public function panel(): array
    {
        $banner = new Banner($this->settings, $this->registry, $this->t);
        return ['html' => $banner->buildHtml()];
    }
}
```

(`shortcode()` and `maybeOutputOpener()` are added in Task 4; `register()` references them but they are implemented next — implement Tasks 3 and 4 together before loading the front end.)

- [ ] **Step 4: Run to verify it passes**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter PanelRouteTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Frontend/ConsentReopen.php tests/PanelRouteTest.php
git commit -m "✅ ConsentReopen: panel REST route returning banner markup"
```

---

## Task 4: Opener output + `[lcmt_cookies_settings]` shortcode

**Files:**
- Modify: `src/Frontend/ConsentReopen.php`
- Test: `tests/ConsentReopenTest.php`

**Interfaces:**
- Consumes: `ClientConfig::build()`, `Consent::isComplete()`, `Assets`-style manifest read.
- Produces:
  - `shortcode($atts): string` — returns `<button type="button" class="lcmt-open-consent">…</button>`; `label` attribute overrides the text (default "Gérer les cookies").
  - `maybeOutputOpener(): void` — for **decided** users only (banner not enqueued), echoes an inline `<script>` defining `window.lcmtConsent` (client config) + `window.lcmtConsentReopen` (`{panelUrl,cssUrl,jsUrl}`) + the delegated-click opener that lazy-loads on first activation.
  - `manifest(): array`, `isDecided(): bool` helpers.

- [ ] **Step 1: Write the failing tests**

`tests/ConsentReopenTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Frontend\ConsentReopen;
use LcmtDev\Consent\Services\ServiceRegistry;

class ConsentReopenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('__')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('rest_url')->alias(fn($p) => 'https://example.test/wp-json/' . ltrim($p, '/'));
        Functions\when('wp_create_nonce')->justReturn('n');
        Functions\when('wp_json_encode')->alias('json_encode');
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['cookieConsent']);
        parent::tearDown();
    }

    private function reopen(array $option): ConsentReopen
    {
        Functions\when('get_option')->justReturn($option);
        $settings = new Settings();
        return new ConsentReopen($settings, new ServiceRegistry($settings), new Translations($settings));
    }

    public function test_shortcode_outputs_button_with_class(): void
    {
        $html = $this->reopen([])->shortcode([]);
        $this->assertStringContainsString('lcmt-open-consent', $html);
        $this->assertStringContainsString('<button', $html);
    }

    public function test_shortcode_label_override(): void
    {
        $html = $this->reopen([])->shortcode(['label' => 'My cookies']);
        $this->assertStringContainsString('My cookies', $html);
    }

    public function test_opener_emitted_for_decided_user(): void
    {
        // GA enabled + cookie has a decision for it → consent complete → decided.
        $_COOKIE['cookieConsent'] = 'googleanalytics=true';
        $reopen = $this->reopen([
            'services' => ['googleanalytics' => ['enabled' => true, 'id' => 'G-XXX', 'category' => 'analytic', 'display_name' => '']],
        ]);

        ob_start();
        $reopen->maybeOutputOpener();
        $out = ob_get_clean();

        $this->assertStringContainsString('lcmtConsentReopen', $out);
        $this->assertStringContainsString('/v1/panel', $out);
        $this->assertStringContainsString('lcmt-open-consent', $out);
    }

    public function test_opener_not_emitted_while_pending(): void
    {
        // GA enabled, no cookie decision → pending → banner handles open(), no opener.
        $reopen = $this->reopen([
            'services' => ['googleanalytics' => ['enabled' => true, 'id' => 'G-XXX', 'category' => 'analytic', 'display_name' => '']],
        ]);

        ob_start();
        $reopen->maybeOutputOpener();
        $out = ob_get_clean();

        $this->assertSame('', $out);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter ConsentReopenTest`
Expected: FAIL — `shortcode()` / `maybeOutputOpener()` undefined.

- [ ] **Step 3: Implement shortcode + opener**

Add to `src/Frontend/ConsentReopen.php` (inside the class). Add the `use` for `Consent` at the top: `use LcmtDev\Consent\Consent;`

```php
    /** @param array<string,mixed>|string $atts */
    public function shortcode($atts = []): string
    {
        $atts = shortcode_atts(['label' => __('Gérer les cookies', 'lcmt-dev-consent')], $atts);
        return '<button type="button" class="lcmt-open-consent">' . esc_html((string) $atts['label']) . '</button>';
    }

    public function isDecided(): bool
    {
        if (!$this->settings->get('enabled', true) || is_admin()) {
            return false;
        }
        $services = $this->registry->all();
        if (empty($services)) {
            return false;
        }
        $keys = array_map(fn($s) => $s->key, $services);
        return Consent::isComplete($keys, $this->settings->effectiveCookieName());
    }

    private function manifest(): array
    {
        $path = LCMT_DEV_CONSENT_DIR . 'assets/dist/manifest.json';
        if (!is_readable($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    public function maybeOutputOpener(): void
    {
        if (!$this->isDecided()) {
            return; // pending → full banner is present and provides open()
        }

        $manifest = $this->manifest();
        $jsFile = $manifest['banner.js'] ?? null;
        $cssFile = $manifest['banner.css'] ?? null;
        if (!$jsFile || !$cssFile) {
            return;
        }

        $config = ClientConfig::build($this->settings, $this->registry);
        $reopen = [
            'panelUrl' => rest_url(RestController::NAMESPACE . self::PANEL_ROUTE),
            'cssUrl' => LCMT_DEV_CONSENT_URL . 'assets/dist/' . $cssFile,
            'jsUrl' => LCMT_DEV_CONSENT_URL . 'assets/dist/' . $jsFile,
        ];

        $configJson = wp_json_encode($config);
        $reopenJson = wp_json_encode($reopen);
        ?>
        <script id="lcmt-consent-opener">
        window.lcmtConsent = window.lcmtConsent || <?= $configJson ?>;
        window.lcmtConsentReopen = <?= $reopenJson ?>;
        (function () {
            var loading = false;
            function ensureAndOpen() {
                if (window.__lcmtBannerReady) { window.lcmtConsent.open && window.lcmtConsent.open(); return; }
                if (loading) { return; }
                loading = true;
                window.__lcmtConsentOpenRequested = true;
                var cfg = window.lcmtConsentReopen;
                fetch(cfg.panelUrl, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.html && !document.getElementById('lcmt-consent')) {
                            var wrap = document.createElement('div');
                            wrap.innerHTML = data.html.trim();
                            if (wrap.firstChild) { document.body.appendChild(wrap.firstChild); }
                        }
                        var link = document.createElement('link');
                        link.rel = 'stylesheet'; link.href = cfg.cssUrl;
                        document.head.appendChild(link);
                        var s = document.createElement('script');
                        s.src = cfg.jsUrl; s.async = true;
                        document.body.appendChild(s);
                    })
                    .catch(function () { loading = false; });
            }
            document.addEventListener('click', function (e) {
                var trigger = e.target.closest && e.target.closest('.lcmt-open-consent');
                if (trigger) { e.preventDefault(); ensureAndOpen(); }
            });
            // Expose a stub so window.lcmtConsent.open() works before the bundle loads.
            if (typeof window.lcmtConsent.open !== 'function') {
                window.lcmtConsent.open = ensureAndOpen;
            }
        })();
        </script>
        <?php
    }
```

Note: `shortcode_atts` is a WP function; stub it in the test setUp. **Add to `tests/ConsentReopenTest.php` `setUp()`:**
```php
        Functions\when('shortcode_atts')->alias(fn($d, $a) => array_merge($d, (array) $a));
```

- [ ] **Step 4: Run to verify it passes**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter ConsentReopenTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Frontend/ConsentReopen.php tests/ConsentReopenTest.php
git commit -m "✨ ConsentReopen: lazy opener script + [lcmt_cookies_settings] shortcode"
```

---

## Task 5: banner.ts — `open()` / `openPanel()` + lazy-boot open

**Files:**
- Modify: `assets/src/types.ts`, `assets/src/banner.ts`
- Rebuild: `assets/dist/*` + `manifest.json`

**Interfaces:**
- Consumes: `window.lcmtConsentReopen` (presence ⇒ lazy boot), `window.__lcmtConsentOpenRequested`.
- Produces: `window.lcmtConsent.open(): void` (real impl, replaces the stub) and `window.__lcmtBannerReady = true`. Opens the **panel** view regardless of consent state.

- [ ] **Step 1: Extend the config type**

In `assets/src/types.ts`, add to `LcmtConsentConfig`:
```ts
    log?: LogConfig;
    open?: () => void;
}

declare global {
    interface Window {
        lcmtConsent?: LcmtConsentConfig;
        __lcmtConsentOpenRequested?: boolean;
        __lcmtBannerReady?: boolean;
    }
}
```
(Replace the existing `declare global { interface Window { lcmtConsent?: LcmtConsentConfig; } }` block with the above; keep the single `lcmtConsent?` declaration — do not duplicate it.)

- [ ] **Step 2: Add `openPanel()` to the banner class**

In `assets/src/banner.ts`, add a public method to `ConsentBanner` (e.g. after `hide()`):
```ts
    public openPanel(): void {
        // Re-sync from the current cookie so the panel reflects saved choices.
        this.values = this.parseCookie();
        this.services.forEach((svc) => {
            if (!this.values.find((v) => v.key === svc.key)) {
                this.values.push({ key: svc.key, status: "wait" });
            }
        });
        this.values = this.values.filter((v) => this.services.some((s) => s.key === v.key));
        this.values.forEach((v) => this.updateServiceButtons(v.key, v.status));
        this.root.setAttribute("data-opened", "true");
        this.showPanel();
    }
```

- [ ] **Step 3: Wire the global open API + lazy-boot open in `boot()`**

In `assets/src/banner.ts`, replace the `boot()` function with:
```ts
function boot() {
    const root = document.getElementById("lcmt-consent");
    if (!root) return;
    const banner = new ConsentBanner(root);
    window.lcmtConsent = window.lcmtConsent || ({} as NonNullable<typeof window.lcmtConsent>);
    window.lcmtConsent.open = () => banner.openPanel();
    window.__lcmtBannerReady = true;
    if (window.__lcmtConsentOpenRequested) {
        window.__lcmtConsentOpenRequested = false;
        banner.openPanel();
    }
}
```
(The `ConsentBanner` constructor already returns early when `#lcmt-consent-main`/`-panel` are missing; with the injected panel markup present, `openPanel()` works.)

- [ ] **Step 4: Type-check + build**

Run: `npx tsc --noEmit`
Expected: `No errors found`.
Run: `npm run build`
Expected: webpack compiles; new `banner.[hash].js` + updated `manifest.json`.

- [ ] **Step 5: Commit**

```bash
git add assets/src/ assets/dist/
git commit -m "✨ banner.ts: window.lcmtConsent.open() + lazy-boot panel open"
```

---

## Task 6: Wire Plugin, admin help, i18n, docs

**Files:**
- Modify: `src/Plugin.php`, `src/Admin/SettingsPage.php`, `languages/lcmt-dev-consent-fr_FR.po` (+ `.mo`), `.claude/frontend.md`, `.claude/extending.md`, `.claude/admin-settings.md`
- (Manual verification for the end-to-end reopen.)

**Interfaces:**
- Consumes: `ConsentReopen` (Task 3/4).
- Produces: `ConsentReopen` registered; reopen help section in the Privacy tab.

- [ ] **Step 1: Wire `ConsentReopen` in `Plugin::boot()`**

In `src/Plugin.php` add the use:
```php
use LcmtDev\Consent\Frontend\ConsentReopen;
```
And register it near the other front-end registrations (after the `CookieTable` line):
```php
        (new CookieTable($cookies, $translations))->register();
        (new ConsentReopen($settings, $registry, $translations))->register();
```

- [ ] **Step 2: Lint Plugin**

Run: `php -d memory_limit=512M -l src/Plugin.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Add the reopen help section to `tab_privacy()`**

In `src/Admin/SettingsPage.php`, inside `tab_privacy()`, after the live-preview `echo $this->cookieTable()->render([]);` line, append:
```php
        ?>
        <hr style="margin:28px 0">
        <h3><?= esc_html__('Let users manage their cookies', 'lcmt-dev-consent') ?></h3>
        <p class="description"><?= esc_html__('RGPD Art. 7(3): users must be able to change or withdraw consent as easily as they gave it. Add one of these anywhere (e.g. a footer menu item) to reopen the preferences panel:', 'lcmt-dev-consent') ?></p>
        <p><strong><?= esc_html__('Shortcode', 'lcmt-dev-consent') ?>:</strong> <code>[lcmt_cookies_settings]</code></p>
        <p><strong><?= esc_html__('HTML link', 'lcmt-dev-consent') ?>:</strong> <code>&lt;a href="#" class="lcmt-open-consent"&gt;<?= esc_html__('Gérer les cookies', 'lcmt-dev-consent') ?>&lt;/a&gt;</code></p>
        <p><strong><?= esc_html__('JavaScript', 'lcmt-dev-consent') ?>:</strong> <code>window.lcmtConsent.open()</code></p>
        <p><?= esc_html__('Preview:', 'lcmt-dev-consent') ?> <?php echo $this->reopenButtonPreview(); ?></p>
        <?php
```

And add a tiny helper method to the class (reuses the shortcode markup without a circular dependency):
```php
    private function reopenButtonPreview(): string
    {
        return '<button type="button" class="button lcmt-open-consent">'
            . esc_html__('Gérer les cookies', 'lcmt-dev-consent')
            . '</button>';
    }
```

- [ ] **Step 4: Lint SettingsPage**

Run: `php -d memory_limit=512M -l src/Admin/SettingsPage.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Add French translations**

Append to `languages/lcmt-dev-consent-fr_FR.po` (before EOF):
```po
# Reopen consent ("Gérer les cookies")
msgid "Gérer les cookies"
msgstr "Gérer les cookies"

msgid "Let users manage their cookies"
msgstr "Permettre aux utilisateurs de gérer leurs cookies"

msgid "RGPD Art. 7(3): users must be able to change or withdraw consent as easily as they gave it. Add one of these anywhere (e.g. a footer menu item) to reopen the preferences panel:"
msgstr "RGPD Art. 7(3) : les utilisateurs doivent pouvoir modifier ou retirer leur consentement aussi facilement qu'ils l'ont donné. Ajoutez l'un de ces éléments où vous voulez (par ex. dans le menu de pied de page) pour rouvrir le panneau de préférences :"

msgid "Shortcode"
msgstr "Code court"

msgid "HTML link"
msgstr "Lien HTML"

msgid "JavaScript"
msgstr "JavaScript"

msgid "Preview:"
msgstr "Aperçu :"
```

- [ ] **Step 6: Recompile the `.mo`**

Run: `cd /Users/amaurylecomte/Freelance/omnubo/wp-content/plugins/lcmt-dev-consent/languages && msgfmt lcmt-dev-consent-fr_FR.po -o lcmt-dev-consent-fr_FR.mo && msgfmt --statistics lcmt-dev-consent-fr_FR.po -o /dev/null`
Expected: "compiled" + increased count, no errors.

- [ ] **Step 7: Update docs**

- `.claude/frontend.md`: add the reopen flow — `ConsentReopen` (decided-state inline opener, `window.lcmtConsentReopen`, lazy bundle injection), `Banner::buildHtml()`, the `GET /v1/panel` route, `ClientConfig` shared builder, and `window.lcmtConsent.open()`.
- `.claude/extending.md`: document the three triggers (`.lcmt-open-consent` class, `window.lcmtConsent.open()`, `[lcmt_cookies_settings]` shortcode with `label`).
- `.claude/admin-settings.md`: note the Privacy tab now also documents the reopen triggers.

- [ ] **Step 8: Run the full suite**

Run: `php -d memory_limit=512M vendor/bin/phpunit`
Expected: all PASS.

- [ ] **Step 9: Manual end-to-end verification**

1. Accept cookies, reload (now a decided session). View source: no `banner.[hash].js` enqueued, but a `#lcmt-consent-opener` inline script is present.
2. Add `[lcmt_cookies_settings]` to a page (or a footer link with `class="lcmt-open-consent"`). Click it → panel opens (one fetch to `/v1/panel`, bundle loads once).
3. Change a choice → OK: a consent-log row is recorded; a previously-accepted script is removed after reload.
4. `window.lcmtConsent.open()` in the console also opens the panel. Second click does not re-load the bundle.

- [ ] **Step 10: Commit**

```bash
git add src/Plugin.php src/Admin/SettingsPage.php languages/ .claude/
git commit -m "✨ Wire reopen consent + admin help + translations + docs"
```

---

## Self-Review

**Spec coverage:**
- Triggers: class + JS API + shortcode → Task 4 (`shortcode`, opener click delegation, `open` stub), Task 5 (real `open`). ✓
- Lazy-load on first trigger; zero bundle for non-clickers → Task 4 (`maybeOutputOpener` only for decided users; bundle injected on click). ✓
- Panel markup via REST reusing Banner → Task 1 (`buildHtml`) + Task 3 (`/v1/panel`). ✓
- Reopen opens granular panel pre-filled → Task 5 (`openPanel` re-syncs from cookie, shows panel). ✓
- Shared client config → Task 2 (`ClientConfig`), used by Assets + opener. ✓
- Ties into commit/log/reload → unchanged `commit()` path (Task 5 only opens the panel; OK button still runs existing commit). ✓
- Admin help in existing Privacy tab → Task 6 step 3. ✓
- i18n + docs → Task 6. ✓
- Edge cases (double-click guard, markup-injected-twice check, pending-state uses banner open) → Task 4 opener (`loading` guard, `getElementById` check), Task 4 `isDecided` gating. ✓

**Placeholder scan:** No TBD/TODO; all code shown. Cross-task note: Tasks 3 and 4 ship one class (`register()` in Task 3 references methods built in Task 4) — flagged to implement together. ✓

**Type consistency:** `buildHtml()`, `ClientConfig::build()`, `ConsentReopen::{panel,shortcode,maybeOutputOpener,isDecided}`, `window.lcmtConsent.open()`, `window.__lcmtConsentOpenRequested`, `window.__lcmtBannerReady`, `window.lcmtConsentReopen.{panelUrl,cssUrl,jsUrl}` are consistent across PHP (Tasks 3–4, 6) and TS (Task 5). `RestController::NAMESPACE`/`ROUTE` reused for URLs. ✓
