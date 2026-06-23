# Cookie Table Shortcode + Privacy Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `[lcmt_cookies_table]` shortcode that renders a CNIL-style cookie table for enabled services, with shipped-default cookie data, a per-service admin editor, and a "Politique de confidentialité" admin tab (instructions + live preview).

**Architecture:** Cookie rows are resolved per service by a new `CookieRegistry` (admin override → shipped default → filter), rendered to an HTML table by a new `CookieTable` shortcode class. The Services tab gains a repeatable per-service cookie editor; a new read-only Privacy tab shows the shortcode and a live preview. Cookie data is server-only (never sent to the browser).

**Tech Stack:** PHP 7.4+ WordPress plugin (inline PSR-4-ish autoload under `LcmtDev\Consent\`), single autoloaded option row, WP Shortcode API, TypeScript/SCSS (webpack) — *no TS build needed for this feature* (admin editor uses vanilla inline JS; table CSS is emitted inline by PHP). Tests: PHPUnit 9 + Brain\Monkey (already set up under `tests/`).

## Global Constraints

- **PHP floor:** 7.4 (typed properties + arrow functions).
- **Namespace:** `LcmtDev\Consent\…`, loaded by the inline autoloader in `lcmt-dev-consent.php` (strip prefix → `src/`, `\`→`/`).
- **Settings:** single autoloaded option `lcmt_dev_consent_settings` via `Settings::all()/get()/save()`; reads cached in-memory per request. New keys go through `Settings::defaults()` and exactly one branch of `SettingsPage::sanitizeForTab()`.
- **Per-tab save:** the admin form submits only the current tab's slice; the cookie editor lives in the **services** tab and is sanitized in the `services` branch.
- **i18n:** all user-facing strings via `__()`/`esc_html__()` (text domain `lcmt-dev-consent`). Shipped-default cookie text is rendered through `Translations::translatePassthrough()` so the `.mo` localizes it; admin-entered text is shown unchanged. French translations for shipped defaults + new labels go in `languages/lcmt-dev-consent-fr_FR.po`, recompiled to `.mo` with `msgfmt`.
- **Purpose wording:** every shipped-default `purpose` is a full descriptive sentence (CNIL-style), never a one-word category.
- **Escaping:** all dynamic output escaped (`esc_html`, `esc_url`, `esc_attr`).
- **Cookie data is server-only:** never added to `Service::toClientConfig()` / `window.lcmtConsent`.
- **Scope:** the rendered table lists only enabled services (`ServiceRegistry::all()` is already enabled-only).
- **Tests:** run with `php -d memory_limit=512M vendor/bin/phpunit` from the plugin root (the environment sets a bogus `memory_limit=2`, so the flag is required).
- **Docs:** update `.claude/*.md` in the same change (per the plugin's CLAUDE.md).

---

## File Structure

**Create:**
- `src/Services/CookieRegistry.php` — resolve/normalize cookie rows; flat row list; essential row.
- `src/Frontend/CookieTable.php` — `[lcmt_cookies_table]` shortcode + scoped CSS.
- `tests/CookieRegistryTest.php`, `tests/CookieTableTest.php`, `tests/CookieSanitizeTest.php`.

**Modify:**
- `src/Admin/Settings.php` — `service_cookies` default + `defaultServiceCookies()`.
- `src/Services/Service.php` — new `cookies` array property (from filter args).
- `src/Services/ServiceRegistry.php` — pass `cookies` through from the filter.
- `src/Plugin.php` — instantiate + register `CookieTable`.
- `src/Admin/SettingsPage.php` — per-service cookie editor (Services tab), `service_cookies` sanitization, new `privacy` tab.
- `languages/lcmt-dev-consent-fr_FR.po` (+ recompiled `.mo`).
- `.claude/services.md`, `.claude/admin-settings.md`, `.claude/extending.md`.

---

## Task 1: Service gains a `cookies` property

**Files:**
- Modify: `src/Services/Service.php`
- Modify: `src/Services/ServiceRegistry.php:84-94` (filter loop)
- Test: `tests/ServiceCookiesTest.php`

**Interfaces:**
- Produces: `Service::$cookies` (`array`, default `[]`), populated from `$args['cookies']` when it's an array. `toClientConfig()` unchanged (no `cookies`). The `lcmt_dev_consent_services` filter may include a `cookies` array per service.

- [ ] **Step 1: Write the failing test**

`tests/ServiceCookiesTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use LcmtDev\Consent\Services\Service;

class ServiceCookiesTest extends TestCase
{
    public function test_cookies_defaults_to_empty_array(): void
    {
        $svc = new Service(['key' => 'x']);
        $this->assertSame([], $svc->cookies);
    }

    public function test_cookies_populated_from_args(): void
    {
        $rows = [['name' => '_x', 'purpose' => 'P', 'retention' => '1 day']];
        $svc = new Service(['key' => 'x', 'cookies' => $rows]);
        $this->assertSame($rows, $svc->cookies);
    }

    public function test_cookies_absent_from_client_config(): void
    {
        $svc = new Service(['key' => 'x', 'cookies' => [['name' => '_x']]]);
        $this->assertArrayNotHasKey('cookies', $svc->toClientConfig());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter ServiceCookiesTest`
Expected: FAIL — `Undefined property … $cookies` / access error.

- [ ] **Step 3: Add the property in `Service.php`**

In `src/Services/Service.php`, add the property declaration next to `public string $source;`:
```php
    public string $source; // 'ui' or 'code'
    /** @var array<int,array<string,mixed>> Cookie metadata rows for the CNIL cookie table. */
    public array $cookies;
```
And in the constructor, after `$this->source = …;`:
```php
        $this->cookies = is_array($args['cookies'] ?? null) ? $args['cookies'] : [];
```

- [ ] **Step 4: Pass `cookies` through the filter in `ServiceRegistry.php`**

The filter loop already does `$svc = new Service($args);` with the full `$args`, so `cookies` flows through automatically. Confirm no change is needed by re-reading `ServiceRegistry::all()` lines 84-94; the `$args` array (which may contain `cookies`) is passed wholesale to `new Service($args)`. **No code change required in this step** — it is covered by the constructor.

- [ ] **Step 5: Run to verify it passes**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter ServiceCookiesTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Services/Service.php tests/ServiceCookiesTest.php
git commit -m "✅ Service: carry cookie metadata rows from the services filter"
```

---

## Task 2: Settings — `service_cookies` default + `defaultServiceCookies()`

**Files:**
- Modify: `src/Admin/Settings.php`
- Test: `tests/DefaultServiceCookiesTest.php`

**Interfaces:**
- Produces:
  - `Settings::defaults()` includes `'service_cookies' => []`.
  - `Settings::defaultServiceCookies(): array` — `map<service_key, cookie_row[]>` where each row is `['name','purpose','retention','issuer','third_party'(bool),'url']`. Keys: `googleanalytics`, `googletagmanager`, `facebookpixel`, `matomo`, `youtube`, `google_analytics_storage`, `google_ad_storage`, `google_ad_user_data`, `google_ad_personalization`. Purposes are full descriptive English sentences.

- [ ] **Step 1: Write the failing test**

`tests/DefaultServiceCookiesTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use LcmtDev\Consent\Admin\Settings;

class DefaultServiceCookiesTest extends TestCase
{
    public function test_service_cookies_default_is_empty_array(): void
    {
        $this->assertSame([], Settings::defaults()['service_cookies']);
    }

    public function test_ga_defaults_have_descriptive_purposes(): void
    {
        $all = Settings::defaultServiceCookies();
        $this->assertArrayHasKey('googleanalytics', $all);

        $names = array_column($all['googleanalytics'], 'name');
        $this->assertContains('_ga', $names);
        $this->assertContains('_gid', $names);

        $ga = null;
        foreach ($all['googleanalytics'] as $row) {
            if ($row['name'] === '_ga') {
                $ga = $row;
            }
        }
        $this->assertNotNull($ga);
        $this->assertSame('13 months', $ga['retention']);
        $this->assertTrue($ga['third_party']);
        // Descriptive sentence, not a one-word category.
        $this->assertGreaterThan(20, strlen($ga['purpose']));
    }

    public function test_every_row_has_all_keys(): void
    {
        foreach (Settings::defaultServiceCookies() as $rows) {
            foreach ($rows as $row) {
                foreach (['name', 'purpose', 'retention', 'issuer', 'third_party', 'url'] as $k) {
                    $this->assertArrayHasKey($k, $row);
                }
            }
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter DefaultServiceCookiesTest`
Expected: FAIL — `service_cookies` key missing / `defaultServiceCookies` undefined.

- [ ] **Step 3: Add the `service_cookies` default**

In `src/Admin/Settings.php`, inside `defaults()`, add next to the other new log keys:
```php
            'log_retention_months' => 36,
            'service_cookies' => [],
            'custom_css' => '',
```

- [ ] **Step 4: Add `defaultServiceCookies()`**

Add this method to the `Settings` class (after `predefinedServiceMeta()`):
```php
    /**
     * Curated, accurate default cookie metadata per service for the CNIL cookie
     * table. Purposes are full descriptive sentences (English source strings,
     * localized via the .mo). Admin overrides live in the `service_cookies`
     * option key; code services supply their own via the services filter.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function defaultServiceCookies(): array
    {
        $google = 'https://policies.google.com/privacy';
        return [
            'googleanalytics' => [
                ['name' => '_ga', 'purpose' => 'Registers a unique ID used to generate statistical data on how the visitor uses the site.', 'retention' => '13 months', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
                ['name' => '_gat (or _dc_gtm_<property-id>)', 'purpose' => 'Used to throttle the request rate to Google Analytics.', 'retention' => '1 minute', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
                ['name' => '_gid', 'purpose' => 'Used to distinguish users.', 'retention' => '1 day', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
                ['name' => '_ga_<container-id>', 'purpose' => 'Persists session state for Google Analytics 4.', 'retention' => '13 months', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
            ],
            'googletagmanager' => [
                ['name' => '_dc_gtm_<property-id>', 'purpose' => 'Used to throttle the request rate to scripts loaded through Google Tag Manager.', 'retention' => '1 minute', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
            ],
            'facebookpixel' => [
                ['name' => '_fbp', 'purpose' => 'Used by Meta to deliver and measure advertising and to identify the visitor\'s browser across sites.', 'retention' => '3 months', 'issuer' => 'Meta', 'third_party' => true, 'url' => 'https://www.facebook.com/policy.php'],
                ['name' => '_fbc', 'purpose' => 'Stores the last advertising click to attribute conversions for Meta advertising.', 'retention' => '3 months', 'issuer' => 'Meta', 'third_party' => true, 'url' => 'https://www.facebook.com/policy.php'],
            ],
            'matomo' => [
                ['name' => '_pk_id', 'purpose' => 'Stores a unique visitor ID used to generate site usage statistics.', 'retention' => '13 months', 'issuer' => 'Matomo', 'third_party' => false, 'url' => 'https://matomo.org/privacy-policy/'],
                ['name' => '_pk_ses', 'purpose' => 'Stores temporary session data used to group a visitor\'s actions during a visit.', 'retention' => '30 minutes', 'issuer' => 'Matomo', 'third_party' => false, 'url' => 'https://matomo.org/privacy-policy/'],
            ],
            'youtube' => [
                ['name' => 'VISITOR_INFO1_LIVE', 'purpose' => 'Estimates the visitor\'s bandwidth on pages that embed YouTube videos.', 'retention' => '6 months', 'issuer' => 'Google (YouTube)', 'third_party' => true, 'url' => $google],
                ['name' => 'YSC', 'purpose' => 'Stores a unique ID to keep statistics of the YouTube videos the visitor has seen.', 'retention' => 'Session', 'issuer' => 'Google (YouTube)', 'third_party' => true, 'url' => $google],
            ],
            'google_analytics_storage' => [
                ['name' => '_ga', 'purpose' => 'Registers a unique ID used to generate statistical data on how the visitor uses the site.', 'retention' => '13 months', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
            ],
            'google_ad_storage' => [
                ['name' => '_gcl_au', 'purpose' => 'Stores and tracks ad conversions for Google advertising services.', 'retention' => '3 months', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
            ],
            'google_ad_user_data' => [
                ['name' => 'IDE', 'purpose' => 'Used by Google to measure and personalize advertising based on user data.', 'retention' => '13 months', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
            ],
            'google_ad_personalization' => [
                ['name' => 'NID', 'purpose' => 'Stores preferences used to personalize Google advertising.', 'retention' => '6 months', 'issuer' => 'Google', 'third_party' => true, 'url' => $google],
            ],
        ];
    }
```

- [ ] **Step 5: Run to verify it passes**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter DefaultServiceCookiesTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Admin/Settings.php tests/DefaultServiceCookiesTest.php
git commit -m "✅ Settings: shipped default cookie metadata + service_cookies option"
```

---

## Task 3: CookieRegistry — normalize + resolve precedence

**Files:**
- Create: `src/Services/CookieRegistry.php`
- Test: `tests/CookieRegistryTest.php`

**Interfaces:**
- Consumes: `Settings`, `ServiceRegistry`, `Service`.
- Produces:
  - `new CookieRegistry(Settings $settings, ServiceRegistry $registry)`.
  - `normalizeRow(array $row): array` — returns a row with exactly the keys
    `name, purpose, retention, issuer, third_party(bool), url`; trims strings;
    casts `third_party` to bool; blanks a `url` that isn't `http(s)`. Static.
  - `forService(Service $s): array` — resolved rows for one service: admin
    override (`settings['service_cookies'][key]`, when the key exists) →
    `Settings::defaultServiceCookies()[key]` → `$s->cookies`. Each row normalized.

- [ ] **Step 1: Write the failing test**

`tests/CookieRegistryTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Services\CookieRegistry;
use LcmtDev\Consent\Services\Service;
use LcmtDev\Consent\Services\ServiceRegistry;

class CookieRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('esc_url_raw')->returnArg(1);
    }

    private function reg(array $option = []): CookieRegistry
    {
        Functions\when('get_option')->justReturn($option);
        $settings = new Settings();
        return new CookieRegistry($settings, new ServiceRegistry($settings));
    }

    public function test_normalize_casts_and_trims(): void
    {
        $row = CookieRegistry::normalizeRow([
            'name' => '  _ga  ', 'purpose' => ' P ', 'retention' => '13 months',
            'issuer' => 'Google', 'third_party' => '1', 'url' => 'https://x.test',
        ]);
        $this->assertSame('_ga', $row['name']);
        $this->assertSame('P', $row['purpose']);
        $this->assertTrue($row['third_party']);
        $this->assertSame('https://x.test', $row['url']);
    }

    public function test_normalize_blanks_bad_url_and_fills_missing(): void
    {
        $row = CookieRegistry::normalizeRow(['name' => '_x', 'url' => 'javascript:alert(1)']);
        $this->assertSame('', $row['url']);
        $this->assertSame('', $row['purpose']);
        $this->assertFalse($row['third_party']);
        $this->assertSame(['name', 'purpose', 'retention', 'issuer', 'third_party', 'url'], array_keys($row));
    }

    public function test_for_service_uses_shipped_default(): void
    {
        $reg = $this->reg();
        $svc = new Service(['key' => 'googleanalytics', 'name' => 'Google Analytics']);
        $rows = $reg->forService($svc);
        $this->assertContains('_ga', array_column($rows, 'name'));
    }

    public function test_admin_override_wins_over_default(): void
    {
        $reg = $this->reg(['service_cookies' => [
            'googleanalytics' => [['name' => '_only', 'purpose' => 'x', 'retention' => 'y', 'issuer' => 'z', 'third_party' => false, 'url' => '']],
        ]]);
        $svc = new Service(['key' => 'googleanalytics', 'name' => 'Google Analytics']);
        $rows = $reg->forService($svc);
        $this->assertSame(['_only'], array_column($rows, 'name'));
    }

    public function test_filter_cookies_used_for_code_service_without_default(): void
    {
        $reg = $this->reg();
        $svc = new Service(['key' => 'hotjar', 'name' => 'Hotjar', 'cookies' => [
            ['name' => '_hjid', 'purpose' => 'p', 'retention' => '1 year', 'issuer' => 'Hotjar', 'third_party' => true, 'url' => ''],
        ]]);
        $this->assertSame(['_hjid'], array_column($reg->forService($svc), 'name'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter CookieRegistryTest`
Expected: FAIL — class `CookieRegistry` not found.

- [ ] **Step 3: Create `CookieRegistry` with `normalizeRow` + `forService`**

`src/Services/CookieRegistry.php`:
```php
<?php

namespace LcmtDev\Consent\Services;

use LcmtDev\Consent\Admin\Settings;

class CookieRegistry
{
    private Settings $settings;
    private ServiceRegistry $registry;

    public function __construct(Settings $settings, ServiceRegistry $registry)
    {
        $this->settings = $settings;
        $this->registry = $registry;
    }

    public static function normalizeRow(array $row): array
    {
        $url = trim((string) ($row['url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = '';
        }
        return [
            'name' => trim((string) ($row['name'] ?? '')),
            'purpose' => trim((string) ($row['purpose'] ?? '')),
            'retention' => trim((string) ($row['retention'] ?? '')),
            'issuer' => trim((string) ($row['issuer'] ?? '')),
            'third_party' => !empty($row['third_party']),
            'url' => $url,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function forService(Service $service): array
    {
        $overrides = (array) $this->settings->get('service_cookies', []);
        if (array_key_exists($service->key, $overrides) && is_array($overrides[$service->key])) {
            $rows = $overrides[$service->key];
        } else {
            $defaults = Settings::defaultServiceCookies();
            $rows = $defaults[$service->key] ?? $service->cookies;
        }

        $out = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = self::normalizeRow($row);
            if ($normalized['name'] === '') {
                continue;
            }
            $out[] = $normalized;
        }
        return $out;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter CookieRegistryTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/CookieRegistry.php tests/CookieRegistryTest.php
git commit -m "✅ CookieRegistry: normalize rows + resolve override/default/filter"
```

---

## Task 4: CookieRegistry — flat rows + essential row

**Files:**
- Modify: `src/Services/CookieRegistry.php`
- Test: `tests/CookieRegistryRowsTest.php`

**Interfaces:**
- Consumes: `ServiceRegistry::all()` (enabled-only), `Settings::effectiveCookieName()`, `Settings::get('cookie_lifetime_days')`, `Translations::translatePassthrough()` (optional, injected).
- Produces:
  - `allRows(bool $includeEssential = true): array` — flat list; each entry is a
    normalized row plus `'service' => <display name>`. Prepends `essentialRow()`
    when `$includeEssential`.
  - `essentialRow(): array` — normalized row + `service` for the plugin's own
    `cookieConsent` cookie (first-party, retention from `cookie_lifetime_days`).

- [ ] **Step 1: Write the failing test**

`tests/CookieRegistryRowsTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Services\CookieRegistry;
use LcmtDev\Consent\Services\ServiceRegistry;

class CookieRegistryRowsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('__')->returnArg(1);
    }

    private function reg(array $option): CookieRegistry
    {
        Functions\when('get_option')->justReturn($option);
        $settings = new Settings();
        return new CookieRegistry($settings, new ServiceRegistry($settings));
    }

    public function test_all_rows_includes_enabled_service_cookies_with_service_name(): void
    {
        $reg = $this->reg(['services' => [
            'googleanalytics' => ['enabled' => true, 'id' => 'G-XXX', 'category' => 'analytic', 'display_name' => ''],
        ]]);
        $rows = $reg->allRows(false);
        $names = array_column($rows, 'name');
        $this->assertContains('_ga', $names);
        foreach ($rows as $r) {
            $this->assertArrayHasKey('service', $r);
            $this->assertNotSame('', $r['service']);
        }
    }

    public function test_disabled_service_contributes_no_rows(): void
    {
        $reg = $this->reg(['services' => [
            'googleanalytics' => ['enabled' => false, 'id' => '', 'category' => 'analytic', 'display_name' => ''],
        ]]);
        $this->assertSame([], $reg->allRows(false));
    }

    public function test_essential_row_present_by_default_and_first_party(): void
    {
        $reg = $this->reg(['cookie_lifetime_days' => 365]);
        $rows = $reg->allRows(true);
        $this->assertNotEmpty($rows);
        $essential = $rows[0];
        $this->assertSame('cookieConsent', $essential['name']);
        $this->assertFalse($essential['third_party']);
        $this->assertStringContainsString('365', $essential['retention']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter CookieRegistryRowsTest`
Expected: FAIL — `allRows`/`essentialRow` undefined.

- [ ] **Step 3: Add `allRows` + `essentialRow`**

Append to the `CookieRegistry` class in `src/Services/CookieRegistry.php`:
```php
    /** @return array<int,array<string,mixed>> */
    public function allRows(bool $includeEssential = true): array
    {
        $rows = [];
        if ($includeEssential) {
            $rows[] = $this->essentialRow();
        }
        foreach ($this->registry->all() as $service) {
            foreach ($this->forService($service) as $row) {
                $row['service'] = $service->name;
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    public function essentialRow(): array
    {
        $days = (int) $this->settings->get('cookie_lifetime_days', 365);
        $row = self::normalizeRow([
            'name' => $this->settings->effectiveCookieName(),
            'purpose' => __('Stores your cookie consent choices so the banner is not shown again.', 'lcmt-dev-consent'),
            'retention' => sprintf(
                /* translators: %d = number of days */
                __('%d days', 'lcmt-dev-consent'),
                $days
            ),
            'issuer' => __('This website', 'lcmt-dev-consent'),
            'third_party' => false,
            'url' => '',
        ]);
        $row['service'] = __('Essential', 'lcmt-dev-consent');
        return $row;
    }
```

Note: `essentialRow()` always reports the cookie name as `cookieConsent` unless the admin renamed it (then it follows `effectiveCookieName()`); the test uses the default name, so it asserts `cookieConsent`.

- [ ] **Step 4: Run to verify it passes**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter CookieRegistryRowsTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Run the full suite**

Run: `php -d memory_limit=512M vendor/bin/phpunit`
Expected: PASS (all prior + new).

- [ ] **Step 6: Commit**

```bash
git add src/Services/CookieRegistry.php tests/CookieRegistryRowsTest.php
git commit -m "✅ CookieRegistry: flat enabled-service rows + essential cookie row"
```

---

## Task 5: CookieTable shortcode + scoped CSS

**Files:**
- Create: `src/Frontend/CookieTable.php`
- Test: `tests/CookieTableTest.php`

**Interfaces:**
- Consumes: `CookieRegistry`, `Translations`.
- Produces:
  - `new CookieTable(CookieRegistry $cookies, Translations $t)`.
  - `register(): void` — `add_shortcode('lcmt_cookies_table', [$this, 'render'])` on `init`.
  - `render($atts): string` — returns the table HTML (or `''` if no rows). Honors `essential` attribute (`"1"` default, `"0"` hides essential row). Emits the scoped `<style>` only once per request (static guard).

- [ ] **Step 1: Write the failing test**

`tests/CookieTableTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Frontend\CookieTable;
use LcmtDev\Consent\Services\CookieRegistry;
use LcmtDev\Consent\Services\ServiceRegistry;

class CookieTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('shortcode_atts')->alias(fn($defaults, $atts) => array_merge($defaults, (array) $atts));
        Functions\when('translate')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);
    }

    private function table(array $option): CookieTable
    {
        Functions\when('get_option')->justReturn($option);
        $settings = new Settings();
        $registry = new ServiceRegistry($settings);
        $cookies = new CookieRegistry($settings, $registry);
        return new CookieTable($cookies, new Translations($settings));
    }

    public function test_render_outputs_table_with_enabled_service_rows(): void
    {
        $html = $this->table(['services' => [
            'googleanalytics' => ['enabled' => true, 'id' => 'G-XXX', 'category' => 'analytic', 'display_name' => ''],
        ]])->render(['essential' => '0']);

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('lcmt-cookies-table', $html);
        $this->assertStringContainsString('_ga', $html);
        $this->assertStringContainsString('Google Analytics', $html);
    }

    public function test_essential_attribute_zero_hides_essential_row(): void
    {
        $html = $this->table(['services' => []])->render(['essential' => '0']);
        $this->assertSame('', $html);
    }

    public function test_essential_row_shown_by_default(): void
    {
        $html = $this->table(['services' => [], 'cookie_lifetime_days' => 365])->render([]);
        $this->assertStringContainsString('cookieConsent', $html);
    }

    public function test_style_emitted_only_once(): void
    {
        $t = $this->table(['services' => [], 'cookie_lifetime_days' => 365]);
        $first = $t->render([]);
        $second = $t->render([]);
        $this->assertSame(1, substr_count($first, '<style'));
        $this->assertSame(0, substr_count($second, '<style'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter CookieTableTest`
Expected: FAIL — class `CookieTable` not found.

- [ ] **Step 3: Create `CookieTable`**

`src/Frontend/CookieTable.php`:
```php
<?php

namespace LcmtDev\Consent\Frontend;

use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Services\CookieRegistry;

class CookieTable
{
    private CookieRegistry $cookies;
    private Translations $t;
    private static bool $styleEmitted = false;

    public function __construct(CookieRegistry $cookies, Translations $t)
    {
        $this->cookies = $cookies;
        $this->t = $t;
    }

    public function register(): void
    {
        add_action('init', function () {
            add_shortcode('lcmt_cookies_table', [$this, 'render']);
        });
    }

    /** @param array<string,mixed>|string $atts */
    public function render($atts = []): string
    {
        $atts = shortcode_atts(['essential' => '1'], $atts);
        $includeEssential = ($atts['essential'] !== '0' && $atts['essential'] !== 0 && $atts['essential'] !== false);

        $rows = $this->cookies->allRows($includeEssential);
        if (empty($rows)) {
            return '';
        }

        $html = $this->styleOnce();
        $html .= '<div class="lcmt-cookies-table-wrap"><table class="lcmt-cookies-table">';
        $html .= '<thead><tr>'
            . '<th>' . esc_html__('Service', 'lcmt-dev-consent') . '</th>'
            . '<th>' . esc_html__('Cookie', 'lcmt-dev-consent') . '</th>'
            . '<th>' . esc_html__('Purpose', 'lcmt-dev-consent') . '</th>'
            . '<th>' . esc_html__('Retention', 'lcmt-dev-consent') . '</th>'
            . '<th>' . esc_html__('Issuer', 'lcmt-dev-consent') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>'
                . '<td>' . esc_html((string) ($row['service'] ?? '')) . '</td>'
                . '<td><code>' . esc_html($row['name']) . '</code></td>'
                . '<td>' . esc_html($this->localize($row['purpose'])) . '</td>'
                . '<td>' . esc_html($this->localize($row['retention'])) . '</td>'
                . '<td>' . $this->issuerCell($row) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    private function localize(string $value): string
    {
        return $value === '' ? '' : $this->t->translatePassthrough($value);
    }

    private function issuerCell(array $row): string
    {
        $issuer = $this->localize((string) $row['issuer']);
        $party = $row['third_party']
            ? esc_html__('third-party', 'lcmt-dev-consent')
            : esc_html__('first-party', 'lcmt-dev-consent');
        $label = esc_html($issuer);
        if ($issuer !== '') {
            $label .= ' ';
        }
        $label .= '(' . $party . ')';
        if (!empty($row['url'])) {
            return '<a href="' . esc_url($row['url']) . '" target="_blank" rel="noopener nofollow">' . $label . '</a>';
        }
        return $label;
    }

    private function styleOnce(): string
    {
        if (self::$styleEmitted) {
            return '';
        }
        self::$styleEmitted = true;
        $css = '.lcmt-cookies-table-wrap{overflow-x:auto;margin:1em 0}'
            . '.lcmt-cookies-table{width:100%;border-collapse:collapse;font-size:14px}'
            . '.lcmt-cookies-table th,.lcmt-cookies-table td{border:1px solid #ddd;padding:8px 10px;text-align:left;vertical-align:top}'
            . '.lcmt-cookies-table thead th{background:#f5f5f5;font-weight:600}'
            . '.lcmt-cookies-table tbody tr:nth-child(even){background:#fafafa}'
            . '.lcmt-cookies-table code{background:transparent;padding:0;font-size:13px}';
        return '<style id="lcmt-cookies-table-css">' . $css . '</style>';
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter CookieTableTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Reset note for the static guard**

The `self::$styleEmitted` static persists across `render()` calls within a request (intended) and across tests in one process. The `test_style_emitted_only_once` test calls `render()` twice on the same instance, so it is order-independent of other tests **only if** it is the first to render with a style. To make it robust, that test asserts `substr_count($first,'<style')===1` — if another test already emitted the style in the same process, `$first` would have 0. Guard against cross-test bleed by resetting the static via reflection in this test's `setUp`. Add to `tests/CookieTableTest.php` `setUp()` (end of method):
```php
        $ref = new \ReflectionProperty(CookieTable::class, 'styleEmitted');
        $ref->setAccessible(true);
        $ref->setValue(null, false);
```

- [ ] **Step 6: Re-run to verify still green**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter CookieTableTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Commit**

```bash
git add src/Frontend/CookieTable.php tests/CookieTableTest.php
git commit -m "✅ CookieTable: [lcmt_cookies_table] shortcode with scoped CSS"
```

---

## Task 6: Wire CookieTable into Plugin boot

**Files:**
- Modify: `src/Plugin.php`
- (Manual verification — shortcode registration.)

**Interfaces:**
- Consumes: `CookieRegistry`, `CookieTable`.
- Produces: shortcode `[lcmt_cookies_table]` registered on every front-end + admin request.

- [ ] **Step 1: Add use statements + instantiation**

In `src/Plugin.php`, add to the `use` block:
```php
use LcmtDev\Consent\Frontend\CookieTable;
use LcmtDev\Consent\Services\CookieRegistry;
```

In `boot()`, after `$log = new ConsentLog($settings);`, add:
```php
        $cookies = new CookieRegistry($settings, $registry);
```

And near the other front-end registrations (after `(new ScriptInjector(...))->register();`), add:
```php
        (new CookieTable($cookies, $translations))->register();
```

- [ ] **Step 2: Lint**

Run: `php -d memory_limit=512M -l src/Plugin.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Manual verification (running site)**

Add `[lcmt_cookies_table]` to any page/post. With Google Analytics enabled in
Settings → Cookie Consent → Services, load the page:
- The table renders with the essential `cookieConsent` row + the GA cookies.
- View source: exactly one `<style id="lcmt-cookies-table-css">`.
- `[lcmt_cookies_table essential="0"]` hides the consent-cookie row.

- [ ] **Step 4: Commit**

```bash
git add src/Plugin.php
git commit -m "✨ Register [lcmt_cookies_table] shortcode"
```

---

## Task 7: Services-tab per-service cookie editor + sanitization

**Files:**
- Modify: `src/Admin/SettingsPage.php`
- Test: `tests/CookieSanitizeTest.php`

**Interfaces:**
- Consumes: `CookieRegistry::forService()` (to pre-fill), `Settings`.
- Produces:
  - `SettingsPage::sanitizeServiceCookies(array $input): array` — turns posted
    `service_cookies` into a clean `map<key, normalized_row[]>` (drops empty-name
    rows, re-indexes, casts `third_party`, validates `url`). Public for testing.
  - The `services` branch of `sanitizeForTab()` returns `service_cookies` too.
  - The Services tab renders, under each predefined service, a repeatable editor.

- [ ] **Step 1: Write the failing test**

`tests/CookieSanitizeTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Admin\SettingsPage;
use LcmtDev\Consent\Log\ConsentLog;
use LcmtDev\Consent\Services\CookieRegistry;
use LcmtDev\Consent\Services\ServiceRegistry;
use LcmtDev\Consent\Tests\Fakes\FakeWpdb;

class CookieSanitizeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('get_option')->justReturn([]);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('sanitize_key')->alias(fn($k) => preg_replace('/[^a-z0-9_]/', '', strtolower($k)));
    }

    private function page(): SettingsPage
    {
        $settings = new Settings();
        $registry = new ServiceRegistry($settings);
        $log = new ConsentLog($settings, new FakeWpdb());
        return new SettingsPage($settings, $registry, new Translations($settings), $log);
    }

    public function test_sanitize_drops_empty_rows_and_reindexes(): void
    {
        $out = $this->page()->sanitizeServiceCookies([
            'googleanalytics' => [
                5 => ['name' => '_ga', 'purpose' => 'P', 'retention' => '13 months', 'issuer' => 'Google', 'third_party' => '1', 'url' => 'https://x.test'],
                6 => ['name' => '', 'purpose' => 'ignored'],
            ],
        ]);

        $this->assertArrayHasKey('googleanalytics', $out);
        $this->assertCount(1, $out['googleanalytics']);
        $this->assertSame(0, array_keys($out['googleanalytics'])[0]); // re-indexed
        $row = $out['googleanalytics'][0];
        $this->assertSame('_ga', $row['name']);
        $this->assertTrue($row['third_party']);
        $this->assertSame('https://x.test', $row['url']);
    }

    public function test_sanitize_skips_service_with_only_empty_rows(): void
    {
        $out = $this->page()->sanitizeServiceCookies([
            'matomo' => [['name' => '', 'purpose' => '']],
        ]);
        $this->assertArrayNotHasKey('matomo', $out);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter CookieSanitizeTest`
Expected: FAIL — `sanitizeServiceCookies` undefined.

- [ ] **Step 3: Add `sanitizeServiceCookies()` and extend the services branch**

In `src/Admin/SettingsPage.php`, add the method (public, near `sanitizeForTab`):
```php
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
```

Then extend the `services` case of `sanitizeForTab()`. Replace its `return ['services' => $services];` with:
```php
                $cookies = $this->sanitizeServiceCookies((array) ($input['service_cookies'] ?? []));
                return ['services' => $services, 'service_cookies' => $cookies];
```

- [ ] **Step 4: Run to verify it passes**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter CookieSanitizeTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Render the editor under each predefined service**

In `src/Admin/SettingsPage.php`, the `SettingsPage` constructor must build a
`CookieRegistry` to pre-fill the editor. Add a property + build it lazily.
Add near the top of the class body:
```php
    private ?\LcmtDev\Consent\Services\CookieRegistry $cookieRegistry = null;

    private function cookieRegistry(): \LcmtDev\Consent\Services\CookieRegistry
    {
        if ($this->cookieRegistry === null) {
            $this->cookieRegistry = new \LcmtDev\Consent\Services\CookieRegistry($this->settings, $this->registry);
        }
        return $this->cookieRegistry;
    }
```

In `tab_services()`, inside the predefined-services `foreach` loop, after the
closing `</tr>` of each service row, add a second row containing the editor. Find:
```php
                        <td><input type="text" name="lcmt[services][<?= esc_attr($key) ?>][display_name]" value="<?= esc_attr($row['display_name'] ?? '') ?>"></td>
                    </tr>
                <?php endforeach; ?>
```
Replace with:
```php
                        <td><input type="text" name="lcmt[services][<?= esc_attr($key) ?>][display_name]" value="<?= esc_attr($row['display_name'] ?? '') ?>"></td>
                    </tr>
                    <tr class="lcmt-cookie-editor-row">
                        <td colspan="5"><?php $this->renderCookieEditor($key); ?></td>
                    </tr>
                <?php endforeach; ?>
```

Add the editor renderer method to the class:
```php
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
                $rows[] = \LcmtDev\Consent\Services\CookieRegistry::normalizeRow($r);
            }
        }
        $tplIndex = '__INDEX__';
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
                <?php $this->cookieRowInputs($serviceKey, $tplIndex, ['name' => '', 'purpose' => '', 'retention' => '', 'issuer' => '', 'third_party' => false, 'url' => '']); ?>
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
```

- [ ] **Step 6: Add the editor's inline JS + styles**

In `adminAssets()`, extend the existing `wp_add_inline_style('wp-color-picker', '…')` block by appending these rules to the CSS string (before the closing `');`):
```css
            .lcmt-cookie-editor{margin:6px 0 2px}
            .lcmt-cookie-editor summary{cursor:pointer;color:#2271b1}
            .lcmt-cookie-rows{width:100%;border-collapse:collapse;margin:8px 0}
            .lcmt-cookie-rows th,.lcmt-cookie-rows td{border:1px solid #eee;padding:4px;vertical-align:top}
            .lcmt-cookie-rows input[type=text],.lcmt-cookie-rows input[type=url]{width:100%}
            .lcmt-cookie-editor-row>td{background:#fbfbfb}
```

Also in `adminAssets()`, after the existing `wp_add_inline_script('wp-color-picker', …)` line, add the editor script:
```php
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
                        var tr = document.createElement('tbody');
                        tr.innerHTML = html.trim();
                        tbody.appendChild(tr.firstChild);
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
```

- [ ] **Step 7: Lint + run full suite**

Run: `php -d memory_limit=512M -l src/Admin/SettingsPage.php && php -d memory_limit=512M vendor/bin/phpunit`
Expected: `No syntax errors detected`; all tests PASS.

- [ ] **Step 8: Manual verification**

Settings → Cookie Consent → Services: each predefined service shows a "Cookies for
the privacy-policy table" `<details>` with its default rows. Add a row, edit a
field, remove a row, Save (Services tab) → values persist on reload; other tabs
unaffected.

- [ ] **Step 9: Commit**

```bash
git add src/Admin/SettingsPage.php tests/CookieSanitizeTest.php
git commit -m "✨ Services tab: per-service cookie editor + sanitization"
```

---

## Task 8: "Politique de confidentialité" admin tab

**Files:**
- Modify: `src/Admin/SettingsPage.php`
- (Manual verification — admin UI.)

**Interfaces:**
- Consumes: `CookieTable::render()` for the preview (reuse). Add a `CookieTable`
  built lazily in `SettingsPage`.
- Produces: a new `privacy` tab (label "Politique de confidentialité") with the
  shortcode + copy button + live preview. No save case (read-only).

- [ ] **Step 1: Add the tab to the tab list**

In `render()`'s `$tabs` array, after `'consent_log' => …`:
```php
            'consent_log' => __('Registre de consentement', 'lcmt-dev-consent'),
            'privacy' => __('Politique de confidentialité', 'lcmt-dev-consent'),
```

- [ ] **Step 2: Add a lazy CookieTable builder**

Add to the class:
```php
    private function cookieTable(): \LcmtDev\Consent\Frontend\CookieTable
    {
        return new \LcmtDev\Consent\Frontend\CookieTable($this->cookieRegistry(), $this->t);
    }
```

- [ ] **Step 3: Add the tab renderer**

Add `tab_privacy()` to the class:
```php
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
    }
```

- [ ] **Step 4: Lint**

Run: `php -d memory_limit=512M -l src/Admin/SettingsPage.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Manual verification**

Settings → Cookie Consent → Politique de confidentialité: shows the shortcode +
Copy button + a live preview table reflecting enabled services. Switching a
service on/off in the Services tab changes the preview after save.

- [ ] **Step 6: Commit**

```bash
git add src/Admin/SettingsPage.php
git commit -m "✨ Admin: Politique de confidentialité tab (shortcode + live preview)"
```

---

## Task 9: French translations + docs

**Files:**
- Modify: `languages/lcmt-dev-consent-fr_FR.po` (+ recompiled `.mo`)
- Modify: `.claude/services.md`, `.claude/admin-settings.md`, `.claude/extending.md`

**Interfaces:** none (i18n + docs).

- [ ] **Step 1: Add French translations for shipped defaults + new labels**

Append to `languages/lcmt-dev-consent-fr_FR.po` (before EOF) a new section. Include the shipped-default purpose strings and all new admin/table strings:
```po
# Cookie table — column headers + admin labels
msgid "Cookie"
msgstr "Cookie"

msgid "Purpose"
msgstr "Fonctionnalité"

msgid "Retention"
msgstr "Durée de conservation"

msgid "Issuer"
msgstr "Émetteur"

msgid "third-party"
msgstr "tiers"

msgid "first-party"
msgstr "interne"

msgid "Essential"
msgstr "Essentiel"

msgid "This website"
msgstr "Ce site"

msgid "Stores your cookie consent choices so the banner is not shown again."
msgstr "Enregistre vos choix de consentement afin que la bannière ne s'affiche plus."

msgid "%d days"
msgstr "%d jours"

msgid "Cookies for the privacy-policy table"
msgstr "Cookies pour le tableau de la politique de confidentialité"

msgid "3rd-party"
msgstr "Tiers"

msgid "Policy URL"
msgstr "URL de la politique"

msgid "Add cookie"
msgstr "Ajouter un cookie"

msgid "Remove"
msgstr "Supprimer"

msgid "Politique de confidentialité"
msgstr "Politique de confidentialité"

msgid "Cookie table shortcode"
msgstr "Code court du tableau des cookies"

msgid "Paste this shortcode into your privacy-policy page to display the table of cookies used by your enabled services."
msgstr "Collez ce code court dans votre page de politique de confidentialité pour afficher le tableau des cookies utilisés par vos services activés."

msgid "Copy"
msgstr "Copier"

msgid "Hide the essential consent cookie with"
msgstr "Masquez le cookie de consentement essentiel avec"

msgid "Live preview"
msgstr "Aperçu en direct"

msgid "This is what visitors will see for your currently enabled services."
msgstr "Voici ce que verront les visiteurs pour vos services actuellement activés."

# Shipped default cookie purposes
msgid "Registers a unique ID used to generate statistical data on how the visitor uses the site."
msgstr "Enregistre un identifiant unique utilisé pour générer des données statistiques sur la façon dont le visiteur utilise le site."

msgid "Used to throttle the request rate to Google Analytics."
msgstr "Utilisé pour réduire le taux de requêtes Google Analytics."

msgid "Used to distinguish users."
msgstr "Utilisé pour distinguer les utilisateurs."

msgid "Persists session state for Google Analytics 4."
msgstr "Conserve l'état de session pour Google Analytics 4."

msgid "Used to throttle the request rate to scripts loaded through Google Tag Manager."
msgstr "Utilisé pour réduire le taux de requêtes des scripts chargés via Google Tag Manager."

msgid "Used by Meta to deliver and measure advertising and to identify the visitor's browser across sites."
msgstr "Utilisé par Meta pour diffuser et mesurer la publicité et pour identifier le navigateur du visiteur sur les sites."

msgid "Stores the last advertising click to attribute conversions for Meta advertising."
msgstr "Enregistre le dernier clic publicitaire pour attribuer les conversions de la publicité Meta."

msgid "Stores a unique visitor ID used to generate site usage statistics."
msgstr "Enregistre un identifiant de visiteur unique utilisé pour générer des statistiques d'utilisation du site."

msgid "Stores temporary session data used to group a visitor's actions during a visit."
msgstr "Enregistre des données de session temporaires utilisées pour regrouper les actions d'un visiteur pendant une visite."

msgid "Estimates the visitor's bandwidth on pages that embed YouTube videos."
msgstr "Estime la bande passante du visiteur sur les pages intégrant des vidéos YouTube."

msgid "Stores a unique ID to keep statistics of the YouTube videos the visitor has seen."
msgstr "Enregistre un identifiant unique pour conserver les statistiques des vidéos YouTube vues par le visiteur."

msgid "Stores and tracks ad conversions for Google advertising services."
msgstr "Enregistre et suit les conversions publicitaires pour les services publicitaires de Google."

msgid "Used by Google to measure and personalize advertising based on user data."
msgstr "Utilisé par Google pour mesurer et personnaliser la publicité à partir des données utilisateur."

msgid "Stores preferences used to personalize Google advertising."
msgstr "Enregistre les préférences utilisées pour personnaliser la publicité Google."
```

- [ ] **Step 2: Recompile the `.mo`**

Run: `cd /Users/amaurylecomte/Freelance/omnubo/wp-content/plugins/lcmt-dev-consent/languages && msgfmt lcmt-dev-consent-fr_FR.po -o lcmt-dev-consent-fr_FR.mo && msgfmt --statistics lcmt-dev-consent-fr_FR.po -o /dev/null`
Expected: "compiled" and an increased translated-message count, no errors.

- [ ] **Step 3: Update `.claude/services.md`**

Add a "Cookie metadata" section: the `cookies` array on `Service`, `Settings::defaultServiceCookies()`, `CookieRegistry` resolution precedence (override → default → filter) + `allRows()`/`essentialRow()`, and that the data is server-only.

- [ ] **Step 4: Update `.claude/admin-settings.md`**

Add the `privacy` tab to the tabs table; add `service_cookies` to the option schema; note the `services` sanitize branch now also returns `service_cookies` (via `sanitizeServiceCookies()`), and the per-service cookie editor.

- [ ] **Step 5: Update `.claude/extending.md`**

Document the `[lcmt_cookies_table]` shortcode (+ `essential` attribute) and the `cookies` key on the `lcmt_dev_consent_services` filter (with the row shape).

- [ ] **Step 6: Run full suite**

Run: `php -d memory_limit=512M vendor/bin/phpunit`
Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
git add languages/ .claude/
git commit -m "🌐 Cookie table: French translations + docs"
```

---

## Self-Review

**Spec coverage:**
- Columns Service/Cookie/Purpose/Retention/Issuer → Task 5 (`render`), header + cells. ✓
- Shipped defaults + admin override + filter → Task 2 (`defaultServiceCookies`), Task 3 (precedence), Task 1 (filter `cookies`). ✓
- Descriptive purpose sentences → Task 2 defaults + Task 9 translations (GA wording matches the spec table). ✓
- Only enabled services → Task 4 (`allRows` iterates `ServiceRegistry::all()`). ✓
- Single flat table, one row per cookie → Task 5. ✓
- Essential consent-cookie row + `essential` attribute → Task 4 (`essentialRow`) + Task 5 (attribute). ✓
- Full per-row admin editor in Services tab → Task 7. ✓
- Privacy tab: instructions + live preview → Task 8. ✓
- i18n via translation layer + French defaults → Task 5 (`translatePassthrough`), Task 9 (`.po`/`.mo`). ✓
- Server-only cookie data → Task 1 (`toClientConfig` unchanged, asserted). ✓
- Scoped CSS once per page → Task 5 (`styleOnce`). ✓
- Resolution precedence override→default→filter → Task 3. ✓
- Shortcode name `[lcmt_cookies_table]` → Tasks 5/6. ✓

**Placeholder scan:** No TBD/TODO; every code step shows complete code. The literal `__INDEX__` token in Task 7 is intentional (JS template placeholder), not a plan placeholder. ✓

**Type consistency:** Row keys `name/purpose/retention/issuer/third_party/url` consistent across Tasks 2, 3, 5, 7. `CookieRegistry` methods (`normalizeRow`, `forService`, `allRows`, `essentialRow`) consistent across Tasks 3–5, 7–8. `CookieTable::render($atts)` consistent across Tasks 5, 6, 8. `sanitizeServiceCookies()` consistent across Task 7. `SettingsPage` already has the 4-arg constructor (from the consent-log work), reused in Task 7's test. ✓
