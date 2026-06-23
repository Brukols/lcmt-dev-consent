# Consent Log ("Registre de consentement") Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a server-side, auditable consent log to `lcmt-dev-consent` so the controller can prove consent was given (RGPD Art. 7 / CNIL).

**Architecture:** The browser banner writes an anonymous `cid` into the existing consent cookie and POSTs each consent event to a new WP REST endpoint. The endpoint reads the authoritative choices + `cid` from the cookie (not the request body), derives a `policy_version` hash + `cookie_version` server-side, and inserts one append-only row into a custom table `wp_lcmt_consent_log`. A new admin tab browses/exports the log; a daily cron purges rows older than a configurable retention window (default 36 months).

**Tech Stack:** PHP 7.4+ (WordPress plugin, no Composer at runtime), `$wpdb` + `dbDelta`, WP REST API, WP-Cron, TypeScript/SCSS compiled with webpack. Tests use PHPUnit 9 + Brain\Monkey (dev-only Composer).

## Global Constraints

- **PHP floor:** 7.4 (codebase uses typed properties + arrow functions). Copy this floor into `composer.json`.
- **PHP namespace:** `LcmtDev\Consent\…`, PSR-4-ish, loaded by the inline autoloader in `lcmt-dev-consent.php` (strip prefix → `src/`, `\` → `/`).
- **Single option row:** all settings live in `lcmt_dev_consent_settings` (autoloaded, serialized) via `Settings::all()/get()/save()`.
- **Cookie name:** always obtain via `Settings::effectiveCookieName()` (handles the `_v{N}` suffix). Never hardcode `cookieConsent`.
- **Cookie format:** `!key=status!key=status…` joined by `!`, parsed by `Consent::getCookies()`. The consent id is stored as an extra `cid=<uuid>` pair in the same cookie and MUST be ignored by `isComplete()`/`isAllowed()` (it already is — they only check service keys).
- **Data minimization (hard rule):** never persist IP, user-agent, or identity in the log table. IP may be used transiently for rate-limiting only (hashed transient key), never stored.
- **Logging is best-effort:** a failed/blocked log request must NEVER prevent the consent cookie from being written client-side.
- **Server is the source of truth for derived fields:** `policy_version`, `cookie_version`, `choices`, and `consent_id` are read/derived server-side from the cookie + settings. The only field taken from the request body is `event`.
- **i18n:** all user-facing admin strings use `__()`/`esc_html__()` with text domain `lcmt-dev-consent`.
- **Docs maintenance:** per the plugin's CLAUDE.md, update `.claude/*.md` in the same commit as any structural change (new class, setting, hook, admin tab).
- **Build artifacts:** `assets/dist/` is committed and shipped; after any `assets/src/*` change run `npm run build` and commit the regenerated hashed files + `manifest.json`.

---

## File Structure

**Create:**
- `src/Log/ConsentLog.php` — table schema/create/upgrade, `insert`, `query`, `exportCsv`, `purgeOlderThan`, `policyVersion`/`hashConfig`, condition builder.
- `src/Log/RestController.php` — registers `POST lcmt-dev-consent/v1/log`, nonce + rate-limit, reads cookie, calls `ConsentLog::insert()`.
- `assets/src/consent-log.ts` — `logConsentEvent(event)` fetch helper + `ensureConsentId`/uuid helpers.
- `composer.json`, `phpunit.xml`, `tests/bootstrap.php`, `tests/TestCase.php`, `tests/Fakes/FakeWpdb.php` — dev test harness.
- `tests/*Test.php` — unit tests per task.

**Modify:**
- `src/Admin/Settings.php` — add `log_enabled` + `log_retention_months` defaults.
- `src/Frontend/Assets.php` — add `log` block (enabled, endpoint, nonce) to `clientConfig()`.
- `src/Admin/SettingsPage.php` — new "Registre de consentement" tab, retention field, CSV export handler, sanitize case.
- `src/Plugin.php` — wire `RestController`, cron purge handler, `ConsentLog::maybeUpgrade()`.
- `lcmt-dev-consent.php` — activation hook (create table + schedule cron), deactivation hook (clear cron).
- `uninstall.php` — drop table + clear cron.
- `assets/src/types.ts` — add `log` field to `LcmtConsentConfig`.
- `assets/src/banner.ts` — preserve/mint `cid`, classify event, call `logConsentEvent` in `commit()`.
- `assets/src/youtube.ts` — preserve/mint `cid`, call `logConsentEvent('custom')` on accept.
- `.gitignore` — add `vendor/`.
- `.claude/architecture.md`, `.claude/admin-settings.md`, `.claude/extending.md` — document the new table, tab, REST route, cron.

---

## Task 1: Test harness + Settings log defaults

**Files:**
- Create: `composer.json`, `phpunit.xml`, `tests/bootstrap.php`, `tests/TestCase.php`
- Modify: `.gitignore`, `src/Admin/Settings.php:51-106` (the `defaults()` array)
- Test: `tests/SettingsDefaultsTest.php`

**Interfaces:**
- Produces: `Settings::defaults()` includes `'log_enabled' => true` and `'log_retention_months' => 36`. Test base class `LcmtDev\Consent\Tests\TestCase` (sets up/tears down Brain\Monkey). Bootstrap defines `ABSPATH` and registers the `LcmtDev\Consent\` autoloader against `src/`.

- [ ] **Step 1: Create the Composer dev manifest**

`composer.json`:
```json
{
    "name": "lcmt-dev/consent-tests",
    "description": "Dev-only test harness for the LCMT Dev Consent plugin.",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=7.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "brain/monkey": "^2.6"
    },
    "scripts": {
        "test": "phpunit"
    },
    "config": {
        "optimize-autoloader": true,
        "sort-packages": true
    }
}
```

- [ ] **Step 2: Create the PHPUnit config**

`phpunit.xml`:
```xml
<?xml version="1.0"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true" failOnWarning="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: Create the test bootstrap + base class**

`tests/bootstrap.php`:
```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Mirror the plugin's runtime autoloader so tests load src/ classes.
spl_autoload_register(function ($class) {
    $prefix = 'LcmtDev\\Consent\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (strpos($relative, 'Tests\\') === 0) {
        $relative = substr($relative, strlen('Tests\\'));
        $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    } else {
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    }
    if (is_readable($path)) {
        require_once $path;
    }
});
```

`tests/TestCase.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
```

- [ ] **Step 4: Ignore the vendor directory**

Add a line to `.gitignore`:
```
vendor/
```

- [ ] **Step 5: Install dev dependencies**

Run: `composer install`
Expected: `vendor/` created, `vendor/bin/phpunit` exists. (If `composer` is unavailable on the machine, install it first; this harness is dev-only and never shipped.)

- [ ] **Step 6: Write the failing test for the new defaults**

`tests/SettingsDefaultsTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use LcmtDev\Consent\Admin\Settings;

class SettingsDefaultsTest extends TestCase
{
    public function test_defaults_include_log_options(): void
    {
        $defaults = Settings::defaults();

        $this->assertArrayHasKey('log_enabled', $defaults);
        $this->assertTrue($defaults['log_enabled']);

        $this->assertArrayHasKey('log_retention_months', $defaults);
        $this->assertSame(36, $defaults['log_retention_months']);
    }
}
```

- [ ] **Step 7: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter test_defaults_include_log_options`
Expected: FAIL — `Failed asserting that an array has the key 'log_enabled'`.

- [ ] **Step 8: Add the defaults**

In `src/Admin/Settings.php`, inside the array returned by `defaults()`, add two keys next to `'custom_css' => ''` (keep `custom_css` last is fine; add before it):
```php
            'log_enabled' => true,
            'log_retention_months' => 36,
            'custom_css' => '',
```

- [ ] **Step 9: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter test_defaults_include_log_options`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add composer.json phpunit.xml tests/ .gitignore src/Admin/Settings.php
git commit -m "✅ Add PHP test harness and consent-log settings defaults"
```

---

## Task 2: `Consent::getConsentId()` helper

**Files:**
- Modify: `src/Consent.php`
- Test: `tests/ConsentIdTest.php`

**Interfaces:**
- Consumes: `Consent::getCookies(string $cookieName): array` (existing).
- Produces: `Consent::getConsentId(string $cookieName = 'cookieConsent'): ?string` — returns the `cid` value from the cookie, or `null` if absent/empty.

- [ ] **Step 1: Write the failing test**

`tests/ConsentIdTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use LcmtDev\Consent\Consent;

class ConsentIdTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE['cookieConsent']);
        parent::tearDown();
    }

    public function test_returns_cid_when_present(): void
    {
        $_COOKIE['cookieConsent'] = '!googleanalytics=true!cid=abc-123';
        $this->assertSame('abc-123', Consent::getConsentId('cookieConsent'));
    }

    public function test_returns_null_when_absent(): void
    {
        $_COOKIE['cookieConsent'] = '!googleanalytics=true';
        $this->assertNull(Consent::getConsentId('cookieConsent'));
    }

    public function test_returns_null_when_no_cookie(): void
    {
        $this->assertNull(Consent::getConsentId('cookieConsent'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter ConsentIdTest`
Expected: FAIL — `Call to undefined method LcmtDev\Consent\Consent::getConsentId()`.

- [ ] **Step 3: Implement the helper**

Append this method inside the `Consent` class in `src/Consent.php` (after `isComplete`):
```php
    public static function getConsentId(string $cookieName = 'cookieConsent'): ?string
    {
        $cookies = self::getCookies($cookieName);
        $cid = $cookies['cid'] ?? null;
        return (is_string($cid) && $cid !== '') ? $cid : null;
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit --filter ConsentIdTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Consent.php tests/ConsentIdTest.php
git commit -m "✅ Add Consent::getConsentId() cookie helper"
```

---

## Task 3: `ConsentLog` — policy-version hashing

**Files:**
- Create: `src/Log/ConsentLog.php`
- Test: `tests/ConsentLogHashTest.php`

**Interfaces:**
- Consumes: `wp_json_encode()` (WP function; stubbed to `json_encode` in tests).
- Produces:
  - `ConsentLog::hashConfig(array $config): string` — static, pure: returns `substr(sha1(wp_json_encode($config)), 0, 12)`.
  - `ConsentLog::policyVersion(Settings $settings, ServiceRegistry $registry): string` — builds a normalized config (service `key/name/category` triples, `texts`, `categories`, `privacy_url`) and returns `hashConfig()` of it.

- [ ] **Step 1: Write the failing test**

`tests/ConsentLogHashTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Log\ConsentLog;

class ConsentLogHashTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_json_encode')->alias('json_encode');
    }

    public function test_hash_is_deterministic_and_short(): void
    {
        $a = ConsentLog::hashConfig(['x' => 1, 'y' => 2]);
        $b = ConsentLog::hashConfig(['x' => 1, 'y' => 2]);

        $this->assertSame($a, $b);
        $this->assertSame(12, strlen($a));
    }

    public function test_hash_changes_when_config_changes(): void
    {
        $a = ConsentLog::hashConfig(['x' => 1]);
        $b = ConsentLog::hashConfig(['x' => 2]);

        $this->assertNotSame($a, $b);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter ConsentLogHashTest`
Expected: FAIL — class `LcmtDev\Consent\Log\ConsentLog` not found.

- [ ] **Step 3: Create the class with the hashing methods**

`src/Log/ConsentLog.php`:
```php
<?php

namespace LcmtDev\Consent\Log;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Services\ServiceRegistry;

class ConsentLog
{
    public const DB_VERSION = '1';
    public const DB_VERSION_OPTION = 'lcmt_dev_consent_db_version';
    public const TABLE_SUFFIX = 'lcmt_consent_log';

    /** @var \wpdb */
    private $wpdb;
    private Settings $settings;

    /**
     * @param \wpdb|null $wpdb Injectable for tests; falls back to the global.
     */
    public function __construct(Settings $settings, $wpdb = null)
    {
        $this->settings = $settings;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
    }

    public static function hashConfig(array $config): string
    {
        return substr(sha1((string) wp_json_encode($config)), 0, 12);
    }

    public function policyVersion(Settings $settings, ServiceRegistry $registry): string
    {
        $services = [];
        foreach ($registry->all() as $svc) {
            $services[] = ['key' => $svc->key, 'name' => $svc->name, 'category' => $svc->category];
        }

        return self::hashConfig([
            'services' => $services,
            'texts' => (array) $settings->get('texts', []),
            'categories' => (array) $settings->get('categories', []),
            'privacy_url' => (string) $settings->get('privacy_url', ''),
        ]);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit --filter ConsentLogHashTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Log/ConsentLog.php tests/ConsentLogHashTest.php
git commit -m "✅ Add ConsentLog policy-version hashing"
```

---

## Task 4: `ConsentLog` — insert, condition builder, purge

**Files:**
- Modify: `src/Log/ConsentLog.php`
- Create: `tests/Fakes/FakeWpdb.php`
- Test: `tests/ConsentLogDbTest.php`

**Interfaces:**
- Consumes: injected `$wpdb` with `prefix`, `insert(table, data, formats)`, `insert_id`, `prepare()`, `query()`.
- Produces:
  - `tableName(): string` → `{$wpdb->prefix}lcmt_consent_log`.
  - `insert(array $record): int` — inserts a row (`consent_id`, `event`, `choices`, `policy_version`, `cookie_version`, `created_at`), returns `insert_id`. Missing `created_at` defaults to `gmdate('Y-m-d H:i:s')`.
  - `buildConditions(array $filters): array` — pure: returns `['where' => string, 'args' => array]` for filters `consent_id`, `event`, `from` (date), `to` (date). Empty filters → `['where' => '', 'args' => []]`.
  - `purgeOlderThan(int $months): int` — deletes rows with `created_at < UTC now - months`, returns affected count.

- [ ] **Step 1: Create the fake `$wpdb`**

`tests/Fakes/FakeWpdb.php`:
```php
<?php

namespace LcmtDev\Consent\Tests\Fakes;

class FakeWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;

    /** @var array<int,array{table:string,data:array,formats:array}> */
    public array $inserts = [];
    /** @var array<int,string> */
    public array $queries = [];
    public int $deleteReturn = 0;

    public function insert($table, $data, $formats)
    {
        $this->inserts[] = ['table' => $table, 'data' => $data, 'formats' => $formats];
        $this->insert_id = count($this->inserts);
        return 1;
    }

    public function prepare($query, ...$args)
    {
        // Naive interpolation good enough for assertions in tests.
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        foreach ($args as $a) {
            $replacement = is_int($a) ? (string) $a : "'" . $a . "'";
            $query = preg_replace('/%[ds]/', $replacement, $query, 1);
        }
        return $query;
    }

    public function query($sql)
    {
        $this->queries[] = $sql;
        return $this->deleteReturn;
    }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/ConsentLogDbTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Log\ConsentLog;
use LcmtDev\Consent\Tests\Fakes\FakeWpdb;

class ConsentLogDbTest extends TestCase
{
    private function makeLog(FakeWpdb $db): ConsentLog
    {
        return new ConsentLog(new Settings(), $db);
    }

    public function test_table_name_uses_prefix(): void
    {
        $db = new FakeWpdb();
        $this->assertSame('wp_lcmt_consent_log', $this->makeLog($db)->tableName());
    }

    public function test_insert_writes_expected_columns_and_returns_id(): void
    {
        $db = new FakeWpdb();
        $id = $this->makeLog($db)->insert([
            'consent_id' => 'abc',
            'event' => 'accept_all',
            'choices' => '{"googleanalytics":true}',
            'policy_version' => 'deadbeef0001',
            'cookie_version' => '0',
            'created_at' => '2026-06-23 10:00:00',
        ]);

        $this->assertSame(1, $id);
        $this->assertCount(1, $db->inserts);
        $data = $db->inserts[0]['data'];
        $this->assertSame('abc', $data['consent_id']);
        $this->assertSame('accept_all', $data['event']);
        $this->assertSame('{"googleanalytics":true}', $data['choices']);
        $this->assertSame('2026-06-23 10:00:00', $data['created_at']);
    }

    public function test_insert_defaults_created_at_when_missing(): void
    {
        $db = new FakeWpdb();
        $this->makeLog($db)->insert(['consent_id' => 'x', 'event' => 'custom']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $db->inserts[0]['data']['created_at']
        );
    }

    public function test_build_conditions_empty(): void
    {
        $db = new FakeWpdb();
        $res = $this->makeLog($db)->buildConditions([]);
        $this->assertSame('', $res['where']);
        $this->assertSame([], $res['args']);
    }

    public function test_build_conditions_filters(): void
    {
        $db = new FakeWpdb();
        $res = $this->makeLog($db)->buildConditions([
            'consent_id' => 'abc',
            'event' => 'withdraw',
            'from' => '2026-01-01',
            'to' => '2026-12-31',
        ]);
        $this->assertStringContainsString('consent_id = %s', $res['where']);
        $this->assertStringContainsString('event = %s', $res['where']);
        $this->assertStringContainsString('created_at >= %s', $res['where']);
        $this->assertStringContainsString('created_at <= %s', $res['where']);
        $this->assertSame(['abc', 'withdraw', '2026-01-01 00:00:00', '2026-12-31 23:59:59'], $res['args']);
    }

    public function test_purge_runs_a_delete_and_returns_count(): void
    {
        $db = new FakeWpdb();
        $db->deleteReturn = 7;
        $deleted = $this->makeLog($db)->purgeOlderThan(36);
        $this->assertSame(7, $deleted);
        $this->assertCount(1, $db->queries);
        $this->assertStringContainsString('DELETE FROM wp_lcmt_consent_log', $db->queries[0]);
        $this->assertStringContainsString('created_at <', $db->queries[0]);
    }
}
```

- [ ] **Step 3: Run to verify it fails**

Run: `vendor/bin/phpunit --filter ConsentLogDbTest`
Expected: FAIL — `Call to undefined method … tableName()`.

- [ ] **Step 4: Implement the methods**

Add to `src/Log/ConsentLog.php` (inside the class):
```php
    public function tableName(): string
    {
        return $this->wpdb->prefix . self::TABLE_SUFFIX;
    }

    public function insert(array $record): int
    {
        $row = array_merge([
            'consent_id' => '',
            'event' => '',
            'choices' => '',
            'policy_version' => '',
            'cookie_version' => '',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], $record);

        $this->wpdb->insert(
            $this->tableName(),
            [
                'consent_id' => (string) $row['consent_id'],
                'event' => (string) $row['event'],
                'choices' => (string) $row['choices'],
                'policy_version' => (string) $row['policy_version'],
                'cookie_version' => (string) $row['cookie_version'],
                'created_at' => (string) $row['created_at'],
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        return (int) $this->wpdb->insert_id;
    }

    /**
     * @return array{where:string,args:array}
     */
    public function buildConditions(array $filters): array
    {
        $clauses = [];
        $args = [];

        if (!empty($filters['consent_id'])) {
            $clauses[] = 'consent_id = %s';
            $args[] = (string) $filters['consent_id'];
        }
        if (!empty($filters['event'])) {
            $clauses[] = 'event = %s';
            $args[] = (string) $filters['event'];
        }
        if (!empty($filters['from'])) {
            $clauses[] = 'created_at >= %s';
            $args[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $clauses[] = 'created_at <= %s';
            $args[] = $filters['to'] . ' 23:59:59';
        }

        return [
            'where' => $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '',
            'args' => $args,
        ];
    }

    public function purgeOlderThan(int $months): int
    {
        $months = max(1, $months);
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . $months . ' months', strtotime(gmdate('Y-m-d H:i:s'))));
        $sql = $this->wpdb->prepare(
            'DELETE FROM ' . $this->tableName() . ' WHERE created_at < %s',
            $cutoff
        );
        return (int) $this->wpdb->query($sql);
    }
```

Note: `buildConditions` returns the empty-string `where` for no filters (test expects `''`), and the `WHERE ` prefix otherwise.

**Correction for the empty case:** the test `test_build_conditions_empty` expects `''`. The code above returns `''` when `$clauses` is empty. ✓

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit --filter ConsentLogDbTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Log/ConsentLog.php tests/Fakes/FakeWpdb.php tests/ConsentLogDbTest.php
git commit -m "✅ Add ConsentLog insert, condition builder, and purge"
```

---

## Task 5: `ConsentLog` — query, CSV export, schema create/upgrade

**Files:**
- Modify: `src/Log/ConsentLog.php`
- Test: `tests/ConsentLogCsvTest.php`

**Interfaces:**
- Consumes: injected `$wpdb` with `get_results`, `get_var`, `get_charset_collate`, plus the WP functions `dbDelta()`, `get_option()`, `update_option()` (stubbed in tests where invoked).
- Produces:
  - `query(array $filters, int $page = 1, int $perPage = 50): array` → `['rows' => array<array>, 'total' => int]`. Page is 1-based; clamps `perPage` to `[1, 500]`.
  - `exportCsv(array $rows): string` — pure: builds a CSV string with header `id,consent_id,event,choices,policy_version,cookie_version,created_at` from an array of row arrays. Uses `php://temp` + `fputcsv`.
  - `createTable(): void` — `dbDelta` of the schema; stores `DB_VERSION` in `DB_VERSION_OPTION`.
  - `maybeUpgrade(): void` — calls `createTable()` only when the stored version differs from `DB_VERSION`.

Only `exportCsv` is unit-tested (pure). `query`/`createTable`/`maybeUpgrade` are verified in the integration step of Task 7.

- [ ] **Step 1: Write the failing test for CSV**

`tests/ConsentLogCsvTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Log\ConsentLog;
use LcmtDev\Consent\Tests\Fakes\FakeWpdb;

class ConsentLogCsvTest extends TestCase
{
    public function test_export_csv_has_header_and_rows(): void
    {
        $log = new ConsentLog(new Settings(), new FakeWpdb());
        $csv = $log->exportCsv([
            [
                'id' => 1,
                'consent_id' => 'abc',
                'event' => 'accept_all',
                'choices' => '{"ga":true}',
                'policy_version' => 'deadbeef0001',
                'cookie_version' => '0',
                'created_at' => '2026-06-23 10:00:00',
            ],
        ]);

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertSame('id,consent_id,event,choices,policy_version,cookie_version,created_at', $lines[0]);
        $this->assertStringContainsString('1,abc,accept_all', $lines[1]);
        $this->assertStringContainsString('2026-06-23 10:00:00', $lines[1]);
    }

    public function test_export_csv_header_only_when_empty(): void
    {
        $log = new ConsentLog(new Settings(), new FakeWpdb());
        $csv = trim($log->exportCsv([]));
        $this->assertSame('id,consent_id,event,choices,policy_version,cookie_version,created_at', $csv);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter ConsentLogCsvTest`
Expected: FAIL — undefined method `exportCsv`.

- [ ] **Step 3: Implement query, CSV, schema**

Add to `src/Log/ConsentLog.php`:
```php
    /**
     * @return array{rows:array,total:int}
     */
    public function query(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $perPage = max(1, min(500, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $cond = $this->buildConditions($filters);
        $table = $this->tableName();

        $countSql = 'SELECT COUNT(*) FROM ' . $table . ' ' . $cond['where'];
        $countSql = $cond['args']
            ? $this->wpdb->prepare($countSql, $cond['args'])
            : $countSql;
        $total = (int) $this->wpdb->get_var($countSql);

        $rowsSql = 'SELECT * FROM ' . $table . ' ' . $cond['where']
            . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $rowsSql = $this->wpdb->prepare($rowsSql, array_merge($cond['args'], [$perPage, $offset]));
        $rows = $this->wpdb->get_results($rowsSql, ARRAY_A);

        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    public function exportCsv(array $rows): string
    {
        $columns = ['id', 'consent_id', 'event', 'choices', 'policy_version', 'cookie_version', 'created_at'];
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $columns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $line[] = $row[$col] ?? '';
            }
            fputcsv($fh, $line);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    public function createTable(): void
    {
        $table = $this->tableName();
        $charset = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            consent_id CHAR(36) NOT NULL DEFAULT '',
            event VARCHAR(20) NOT NULL DEFAULT '',
            choices TEXT NULL,
            policy_version VARCHAR(40) NOT NULL DEFAULT '',
            cookie_version VARCHAR(40) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY consent_id (consent_id),
            KEY event (event),
            KEY policy_version (policy_version),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    public function maybeUpgrade(): void
    {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            $this->createTable();
        }
    }
```

Note the two spaces in `PRIMARY KEY  (id)` and `KEY consent_id (...)` formatting are required by `dbDelta`'s strict parser.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit --filter ConsentLogCsvTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests from Tasks 1–5).

- [ ] **Step 6: Commit**

```bash
git add src/Log/ConsentLog.php tests/ConsentLogCsvTest.php
git commit -m "✅ Add ConsentLog query, CSV export, and schema create/upgrade"
```

---

## Task 6: `RestController` — endpoint, validation, rate limit

**Files:**
- Create: `src/Log/RestController.php`
- Test: `tests/RestControllerTest.php`

**Interfaces:**
- Consumes: `Settings`, `ServiceRegistry`, `ConsentLog`; WP functions `wp_verify_nonce`, `get_transient`, `set_transient`, `register_rest_route`, `rest_ensure_response`, `Consent::getCookies`, `Consent::getConsentId`.
- Produces:
  - `RestController` constructor `(Settings $settings, ServiceRegistry $registry, ConsentLog $log)`.
  - `register(): void` — hooks `rest_api_init` → `registerRoute()`.
  - `isValidEvent(string $event): bool` — pure: true for `accept_all|reject_all|custom|withdraw`.
  - `rateLimitExceeded(string $ip): bool` — increments a per-IP transient (`lcmt_consent_rl_<md5(ip)>`, 60s window); returns true once more than 30 requests occur in the window. Never stores the IP itself in the log.
  - `handle(\WP_REST_Request $request)` — verifies event, rate limit; reads choices + `cid` from the cookie; derives `policy_version` + `cookie_version`; inserts; returns a response array `['logged' => bool]`.

Only `isValidEvent` and `rateLimitExceeded` are unit-tested. `handle`/`registerRoute` are verified in Task 7's integration step.

- [ ] **Step 1: Write the failing tests**

`tests/RestControllerTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Log\ConsentLog;
use LcmtDev\Consent\Log\RestController;
use LcmtDev\Consent\Services\ServiceRegistry;
use LcmtDev\Consent\Tests\Fakes\FakeWpdb;

class RestControllerTest extends TestCase
{
    private function make(): RestController
    {
        $settings = new Settings();
        $registry = new ServiceRegistry($settings);
        $log = new ConsentLog($settings, new FakeWpdb());
        return new RestController($settings, $registry, $log);
    }

    public function test_valid_events(): void
    {
        $c = $this->make();
        foreach (['accept_all', 'reject_all', 'custom', 'withdraw'] as $e) {
            $this->assertTrue($c->isValidEvent($e));
        }
        $this->assertFalse($c->isValidEvent('hack'));
        $this->assertFalse($c->isValidEvent(''));
    }

    public function test_rate_limit_blocks_after_threshold(): void
    {
        $counter = 0;
        Functions\when('get_transient')->alias(function () use (&$counter) {
            return $counter ?: false;
        });
        Functions\when('set_transient')->alias(function ($k, $v) use (&$counter) {
            $counter = $v;
            return true;
        });

        $c = $this->make();
        $blocked = false;
        for ($i = 0; $i < 35; $i++) {
            $blocked = $c->rateLimitExceeded('203.0.113.9');
        }
        $this->assertTrue($blocked);
    }

    public function test_rate_limit_allows_first_request(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $c = $this->make();
        $this->assertFalse($c->rateLimitExceeded('203.0.113.9'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter RestControllerTest`
Expected: FAIL — class `RestController` not found.

- [ ] **Step 3: Implement the controller**

`src/Log/RestController.php`:
```php
<?php

namespace LcmtDev\Consent\Log;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Consent;
use LcmtDev\Consent\Services\ServiceRegistry;

class RestController
{
    public const NAMESPACE = 'lcmt-dev-consent/v1';
    public const ROUTE = '/log';
    private const RATE_LIMIT = 30;       // requests
    private const RATE_WINDOW = 60;      // seconds

    private Settings $settings;
    private ServiceRegistry $registry;
    private ConsentLog $log;

    public function __construct(Settings $settings, ServiceRegistry $registry, ConsentLog $log)
    {
        $this->settings = $settings;
        $this->registry = $registry;
        $this->log = $log;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoute']);
    }

    public function registerRoute(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => function ($request) {
                $nonce = $request->get_header('X-WP-Nonce');
                return (bool) wp_verify_nonce($nonce, 'wp_rest');
            },
            'args' => [
                'event' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public function isValidEvent(string $event): bool
    {
        return in_array($event, ['accept_all', 'reject_all', 'custom', 'withdraw'], true);
    }

    public function rateLimitExceeded(string $ip): bool
    {
        $key = 'lcmt_consent_rl_' . md5($ip);
        $count = (int) get_transient($key);
        $count++;
        set_transient($key, $count, self::RATE_WINDOW);
        return $count > self::RATE_LIMIT;
    }

    public function handle($request)
    {
        if (!(bool) $this->settings->get('log_enabled', true)) {
            return rest_ensure_response(['logged' => false]);
        }

        $event = (string) $request->get_param('event');
        if (!$this->isValidEvent($event)) {
            return rest_ensure_response(['logged' => false]);
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        if ($this->rateLimitExceeded($ip)) {
            return rest_ensure_response(['logged' => false]);
        }

        $cookieName = $this->settings->effectiveCookieName();
        $cookies = Consent::getCookies($cookieName);
        $consentId = $cookies['cid'] ?? '';
        if ($consentId === '') {
            return rest_ensure_response(['logged' => false]);
        }

        $choices = [];
        foreach ($cookies as $key => $status) {
            if ($key === 'cid') {
                continue;
            }
            $choices[$key] = ($status === 'true');
        }

        $this->log->insert([
            'consent_id' => $consentId,
            'event' => $event,
            'choices' => (string) wp_json_encode($choices),
            'policy_version' => $this->log->policyVersion($this->settings, $this->registry),
            'cookie_version' => (string) $this->settings->get('consent_version', 0),
        ]);

        return rest_ensure_response(['logged' => true]);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit --filter RestControllerTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Log/RestController.php tests/RestControllerTest.php
git commit -m "✅ Add consent-log REST controller with nonce + rate limit"
```

---

## Task 7: Wire PHP — Plugin boot, activation, cron, integration check

**Files:**
- Modify: `src/Plugin.php`, `lcmt-dev-consent.php`
- (No new unit test — this is integration; verified against the dev WP install.)

**Interfaces:**
- Consumes: `ConsentLog`, `RestController` from earlier tasks.
- Produces: REST route live, table created on activation + lazily on `maybeUpgrade()`, daily cron `lcmt_dev_consent_purge` calling `purgeOlderThan()`.

- [ ] **Step 1: Wire the controller, upgrade check, and cron handler in `Plugin::boot()`**

In `src/Plugin.php`, add `use` statements:
```php
use LcmtDev\Consent\Log\ConsentLog;
use LcmtDev\Consent\Log\RestController;
```

Replace the body of `boot()` to add the log wiring (keep existing lines, add the new ones):
```php
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
```

Note: `SettingsPage` now takes a 4th constructor arg `$log` — that constructor change is made in Task 10. Until Task 10 lands, this line will error if the admin page is loaded. Implement Tasks 7 and 10 together (or stub the 4th param now and fill the body in Task 10). For subagent execution, do Task 10 immediately after Task 7 before loading wp-admin.

- [ ] **Step 2: Register activation/deactivation hooks in the main file**

In `lcmt-dev-consent.php`, after the `define(...)` constants and before/after the autoloader, add:
```php
register_activation_hook(__FILE__, function () {
    require_once __DIR__ . '/src/Admin/Settings.php';
    require_once __DIR__ . '/src/Log/ConsentLog.php';
    (new \LcmtDev\Consent\Log\ConsentLog(new \LcmtDev\Consent\Admin\Settings()))->createTable();
    if (!wp_next_scheduled('lcmt_dev_consent_purge')) {
        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'lcmt_dev_consent_purge');
    }
});

register_deactivation_hook(__FILE__, function () {
    $ts = wp_next_scheduled('lcmt_dev_consent_purge');
    if ($ts) {
        wp_unschedule_event($ts, 'lcmt_dev_consent_purge');
    }
});
```
(The autoloader is already registered above this point, so the explicit `require_once` lines are belt-and-suspenders for activation context.)

- [ ] **Step 3: Integration verification — table + REST (do after Task 10)**

Deactivate/reactivate the plugin in wp-admin (Plugins screen), then verify the table exists:

Run: `wp db query "SHOW TABLES LIKE '%lcmt_consent_log%';" --skip-column-names`
Expected: one line ending in `lcmt_consent_log`.

Then exercise the endpoint from the browser console on the front-end (logged-in or not):
```js
fetch('/wp-json/lcmt-dev-consent/v1/log', {
  method:'POST',
  headers:{'Content-Type':'application/json','X-WP-Nonce': window.lcmtConsent.log.nonce},
  credentials:'same-origin',
  body: JSON.stringify({event:'accept_all'})
}).then(r=>r.json()).then(console.log)
```
Expected: `{logged: true}` (after a consent cookie with a `cid` exists — Task 9 sets it; before Task 9, expect `{logged:false}` because no `cid`). Confirm a row:

Run: `wp db query "SELECT event,consent_id,policy_version FROM wp_lcmt_consent_log ORDER BY id DESC LIMIT 3;"`
Expected: the inserted row(s).

- [ ] **Step 4: Commit**

```bash
git add src/Plugin.php lcmt-dev-consent.php
git commit -m "✨ Wire consent log: REST route, activation table, daily purge cron"
```

---

## Task 8: Frontend config — expose REST endpoint + nonce

**Files:**
- Modify: `src/Frontend/Assets.php:117-129` (`clientConfig()`)
- Test: `tests/ClientConfigTest.php`

**Interfaces:**
- Consumes: WP `rest_url`, `wp_create_nonce` (stubbed in test).
- Produces: `clientConfig()` returns an additional `'log'` key: `['enabled' => bool, 'endpoint' => string, 'nonce' => string]`.

- [ ] **Step 1: Write the failing test**

`tests/ClientConfigTest.php`:
```php
<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Frontend\Assets;
use LcmtDev\Consent\Services\ServiceRegistry;

class ClientConfigTest extends TestCase
{
    public function test_client_config_includes_log_block(): void
    {
        Functions\when('rest_url')->alias(fn($p) => 'https://example.test/wp-json/' . ltrim($p, '/'));
        Functions\when('wp_create_nonce')->justReturn('nonce123');

        $settings = new Settings();
        $registry = new ServiceRegistry($settings);
        $assets = new Assets($settings, $registry, new Translations($settings));

        $ref = new \ReflectionMethod($assets, 'clientConfig');
        $ref->setAccessible(true);
        $config = $ref->invoke($assets);

        $this->assertArrayHasKey('log', $config);
        $this->assertTrue($config['log']['enabled']);
        $this->assertSame('https://example.test/wp-json/lcmt-dev-consent/v1/log', $config['log']['endpoint']);
        $this->assertSame('nonce123', $config['log']['nonce']);
    }
}
```
Note: `ServiceRegistry`/`Translations` construction must not hit WP at instantiation. If `clientConfig()` triggers WP calls beyond `rest_url`/`wp_create_nonce` (e.g. on `registry->all()` via the `apply_filters`), stub them: add `Functions\when('apply_filters')->returnArg(2);` and `Functions\when('get_option')->justReturn([]);` in the test setup as needed to keep it a pure config-shape assertion.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter ClientConfigTest`
Expected: FAIL — array has no key `log` (or a missing-function error pointing at which WP call to stub; add the stub and re-run).

- [ ] **Step 3: Add the log block to `clientConfig()`**

In `src/Frontend/Assets.php`, extend the array returned by `clientConfig()`:
```php
        return [
            'cookieName' => $this->settings->effectiveCookieName(),
            'cookieLifetimeDays' => (int) $this->settings->get('cookie_lifetime_days', 365),
            'services' => array_values($services),
            'categories' => $categories,
            'texts' => (array) $this->settings->get('texts', []),
            'log' => [
                'enabled' => (bool) $this->settings->get('log_enabled', true),
                'endpoint' => rest_url(\LcmtDev\Consent\Log\RestController::NAMESPACE . \LcmtDev\Consent\Log\RestController::ROUTE),
                'nonce' => wp_create_nonce('wp_rest'),
            ],
        ];
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit --filter ClientConfigTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Frontend/Assets.php tests/ClientConfigTest.php
git commit -m "✅ Expose consent-log endpoint + nonce in client config"
```

---

## Task 9: Frontend TS — mint `cid`, log events, rebuild bundle

**Files:**
- Modify: `assets/src/types.ts`, `assets/src/banner.ts`, `assets/src/youtube.ts`
- Create: `assets/src/consent-log.ts`
- Rebuild: `assets/dist/*` + `manifest.json`

**Interfaces:**
- Consumes: `window.lcmtConsent.log` (`{enabled, endpoint, nonce}`).
- Produces:
  - `consent-log.ts` exports `logConsentEvent(event: string): void` and `ensureConsentId(existing: string | null): string`.
  - The consent cookie now contains a `!cid=<uuid>` pair after any commit / YouTube accept.

- [ ] **Step 1: Add the `log` field to the config type**

In `assets/src/types.ts`, add to `LcmtConsentConfig`:
```ts
export interface LogConfig {
    enabled: boolean;
    endpoint: string;
    nonce: string;
}

export interface LcmtConsentConfig {
    cookieName: string;
    cookieLifetimeDays: number;
    services: ServiceConfig[];
    categories: Record<string, CategoryConfig>;
    texts: Texts;
    log?: LogConfig;
}
```

- [ ] **Step 2: Create the consent-log helper module**

`assets/src/consent-log.ts`:
```ts
import type {} from "./types";

export function generateConsentId(): string {
    const c = (typeof crypto !== "undefined" ? crypto : undefined) as Crypto | undefined;
    if (c && typeof c.randomUUID === "function") {
        return c.randomUUID();
    }
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (ch) => {
        const r = (Math.random() * 16) | 0;
        const v = ch === "x" ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

export function ensureConsentId(existing: string | null): string {
    return existing && existing.length > 0 ? existing : generateConsentId();
}

// Best-effort: never throws, never blocks the consent decision.
export function logConsentEvent(event: string): void {
    const log = window.lcmtConsent?.log;
    if (!log || !log.enabled || !log.endpoint) {
        return;
    }
    try {
        void fetch(log.endpoint, {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-WP-Nonce": log.nonce },
            credentials: "same-origin",
            keepalive: true,
            body: JSON.stringify({ event }),
        }).catch(() => {});
    } catch (_e) {
        /* swallow */
    }
}
```

- [ ] **Step 3: Wire `cid` + logging into `banner.ts`**

In `assets/src/banner.ts`:

1. Add the import at the top, next to the existing `runInjector` import:
```ts
import { ensureConsentId, logConsentEvent } from "./consent-log";
```

2. Add a `consentId` field to the class (next to `private values`):
```ts
    private consentId: string | null = null;
```

3. In `parseCookie()`, capture and strip the `cid` pair so it never enters `values`. Replace the method body:
```ts
    private parseCookie(): CookieValue[] {
        const raw = getCookie(this.cookieName);
        if (!raw) return [];
        const out: CookieValue[] = [];
        raw.split("!")
            .filter((s) => s.length > 0)
            .forEach((s) => {
                const [key, status] = s.split("=");
                if (key === "cid") {
                    this.consentId = status || null;
                    return;
                }
                out.push({ key, status: status as Status });
            });
        return out;
    }
```

4. In `saveCookie()`, append the `cid` pair only when one exists. Replace the method body:
```ts
    private saveCookie(): void {
        let value = this.values.map((v) => `!${v.key}=${v.status}`).join("");
        if (this.consentId) {
            value += `!cid=${this.consentId}`;
        }
        setCookie(this.cookieName, value, this.cookieLifetimeDays);
    }
```

5. In `commit()`, after computing `previouslyAccepted` and before `this.saveCookie()`, mint the id, classify the event, save, and log. Replace the tail of `commit()` (from `this.saveCookie();` onward) with:
```ts
        this.consentId = ensureConsentId(this.consentId);
        const event = this.classifyEvent(previouslyAccepted);
        this.saveCookie();
        logConsentEvent(event);
        this.hide();
        if (needReload) window.location.reload();
```

6. Add the classifier method to the class:
```ts
    private classifyEvent(previouslyAccepted: Record<string, boolean>): string {
        const withdrawn = this.values.some(
            (v) => previouslyAccepted[v.key] === true && v.status !== "true"
        );
        if (withdrawn) return "withdraw";

        const allTrue = this.values.every((v) => v.status === "true");
        if (allTrue) return "accept_all";

        const allFalse = this.values.every((v) => v.status === "false");
        if (allFalse) return "reject_all";

        return "custom";
    }
```

- [ ] **Step 4: Wire `cid` + logging into `youtube.ts`**

In `assets/src/youtube.ts`:

1. Add the import at the top (after the scss import):
```ts
import { ensureConsentId, logConsentEvent } from "./consent-log";
```

2. In `setYoutubeAccepted()`, after building `entries` and before serializing, ensure a `cid` entry and log. Replace the function body from the `const existing = …` line onward:
```ts
    const existing = entries.find((e) => e.key === SERVICE_KEY);
    if (existing) {
        existing.status = "true";
    } else {
        entries.push({ key: SERVICE_KEY, status: "true" });
    }

    const cidEntry = entries.find((e) => e.key === "cid");
    const cid = ensureConsentId(cidEntry ? cidEntry.status : null);
    if (cidEntry) {
        cidEntry.status = cid;
    } else {
        entries.push({ key: "cid", status: cid });
    }

    writeCookie(cookieName, serializeConsentCookie(entries), lifetime);
    logConsentEvent("custom");
```
(`serializeConsentCookie` already serializes every entry including the `cid` pair, so no change there. The server ignores `cid` when building `choices`.)

- [ ] **Step 5: Build the bundle**

Run: `npm install` (if `node_modules` absent) then `npm run build`
Expected: webpack completes; `assets/dist/banner.[newhash].js`, `youtube.[newhash].js`, and `assets/dist/manifest.json` are regenerated with new hashes.

- [ ] **Step 6: Manual smoke test in the browser**

Clear the consent cookie, reload the front-end, click "I accept". Then in DevTools:
- Application → Cookies: the consent cookie value ends with `!cid=<uuid>`.
- Network: a `POST …/v1/log` returns `{logged:true}`.
- Reload and open preferences, refuse a previously-accepted service, save: a second `POST` fires and the row's `event` is `withdraw`.

- [ ] **Step 7: Commit**

```bash
git add assets/src/ assets/dist/
git commit -m "✨ Banner/YouTube: mint consent id and log consent events"
```

---

## Task 10: Admin tab — browse, retention setting, CSV export

**Files:**
- Modify: `src/Admin/SettingsPage.php`
- (Manual verification — admin UI.)

**Interfaces:**
- Consumes: `ConsentLog::query()`, `ConsentLog::exportCsv()`, `Settings`.
- Produces: a new `consent_log` tab; a `log_retention_months` save case; a CSV export handled on `admin_init` (before output) when `lcmt_export_log` is posted.

- [ ] **Step 1: Accept `ConsentLog` in the constructor**

In `src/Admin/SettingsPage.php`:

1. Add `use LcmtDev\Consent\Log\ConsentLog;` near the top with the other `use` lines.
2. Add the property and constructor param:
```php
    private Translations $t;
    private ConsentLog $log;

    public function __construct(Settings $settings, ServiceRegistry $registry, Translations $t, ConsentLog $log)
    {
        $this->settings = $settings;
        $this->registry = $registry;
        $this->t = $t;
        $this->log = $log;
    }
```

- [ ] **Step 2: Register the export handler**

In `register()`, add a hook (export must run before any HTML is sent):
```php
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'handleSave']);
        add_action('admin_init', [$this, 'handleExport']);
        add_action('admin_enqueue_scripts', [$this, 'adminAssets']);
    }
```

- [ ] **Step 3: Add the tab to the tab list and labels**

In `render()`, add the tab to the `$tabs` array:
```php
        $tabs = [
            'general' => __('General', 'lcmt-dev-consent'),
            'appearance' => __('Appearance', 'lcmt-dev-consent'),
            'services' => __('Services', 'lcmt-dev-consent'),
            'categories' => __('Categories', 'lcmt-dev-consent'),
            'advanced' => __('Advanced', 'lcmt-dev-consent'),
            'consent_log' => __('Registre de consentement', 'lcmt-dev-consent'),
        ];
```

- [ ] **Step 4: Add the retention save case**

In `sanitizeForTab()`, add a case before the closing `}`:
```php
            case 'consent_log':
                return [
                    'log_enabled' => !empty($input['log_enabled']),
                    'log_retention_months' => max(1, min(120, (int) ($input['log_retention_months'] ?? 36))),
                ];
```

- [ ] **Step 5: Add the export handler**

Add this method to the class:
```php
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
```

- [ ] **Step 6: Add the tab renderer**

Add this method to the class:
```php
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
```

Note: the `consent_log` tab renders its own inline GET filter form and POST export form. The retention checkbox/number live inside the outer `<form method="post">` from `render()` (so the existing "Save changes" button persists them via the `consent_log` sanitize case). The export and filter forms are nested-sibling forms rendered after, which is valid because the outer form wraps the section; to avoid nested `<form>` tags, the filter/export forms must be closed properly — they are separate complete `<form>…</form>` blocks placed inside the section div, which browsers tolerate as siblings, not nesting. If the host browser mis-handles nested forms, move the retention fields above into their own row and keep them within the main form (already the case) — the GET/POST forms below do not nest because they each open and close their own `<form>`.

- [ ] **Step 7: Manual verification**

1. In wp-admin → Settings → Cookie Consent → "Registre de consentement": the retention fields show (36 default). Change to 24, Save → reloads with "Settings saved."; the value persists.
2. After Task 9's smoke test created rows, the table lists them newest-first; the event filter narrows results.
3. Click "Export CSV" → a `consent-log-*.csv` downloads and opens with the header row + data.

- [ ] **Step 8: Commit**

```bash
git add src/Admin/SettingsPage.php
git commit -m "✨ Admin: Registre de consentement tab with browse, retention, CSV export"
```

---

## Task 11: Uninstall cleanup + documentation

**Files:**
- Modify: `uninstall.php`, `.claude/architecture.md`, `.claude/admin-settings.md`, `.claude/extending.md`, `lcmt-dev-consent.php` (version bump), `package.json` (version bump)
- (No unit test.)

**Interfaces:**
- Produces: `uninstall.php` drops the table, deletes the db-version option, clears the cron event.

- [ ] **Step 1: Extend uninstall cleanup**

Replace the body of `uninstall.php` (keeping the guard):
```php
<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('lcmt_dev_consent_settings');
delete_option('lcmt_dev_consent_db_version');

$table = $wpdb->prefix . 'lcmt_consent_log';
$wpdb->query("DROP TABLE IF EXISTS {$table}");

$ts = wp_next_scheduled('lcmt_dev_consent_purge');
if ($ts) {
    wp_unschedule_event($ts, 'lcmt_dev_consent_purge');
}
```

- [ ] **Step 2: Bump versions**

In `lcmt-dev-consent.php`, change the header `Version:` and the `LCMT_DEV_CONSENT_VERSION` define from `1.0.0` to `1.1.0`. In `package.json`, change `"version": "1.0.0"` to `"1.1.0"`.

- [ ] **Step 3: Update `.claude/architecture.md`**

- Add `src/Log/ConsentLog.php` and `src/Log/RestController.php` to the directory layout.
- Add a "Consent log" section describing: the `wp_lcmt_consent_log` table, the `POST lcmt-dev-consent/v1/log` route (reads choices + `cid` from the cookie, derives `policy_version`/`cookie_version` server-side, best-effort), the `cid` cookie pair, and the daily `lcmt_dev_consent_purge` cron.

- [ ] **Step 4: Update `.claude/admin-settings.md`**

- Add the `consent_log` tab, the `log_enabled` + `log_retention_months` options, and the `consent_log` sanitize case to the options schema / per-tab table.

- [ ] **Step 5: Update `.claude/extending.md`**

- Document that `cid` is embedded in the consent cookie and that `Consent::getConsentId()` returns it; note the REST route + nonce (`wp_rest`) for anyone building a custom banner.

- [ ] **Step 6: Run the full test suite one last time**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests).

- [ ] **Step 7: Commit**

```bash
git add uninstall.php lcmt-dev-consent.php package.json .claude/
git commit -m "🧹 Consent log: uninstall cleanup, version bump 1.1.0, docs"
```

---

## Self-Review

**Spec coverage:**
- Record columns (consent_id, event, choices, policy_version, cookie_version, created_at; no IP/identity) → Tasks 4–5 (schema) + 6 (insert path). ✓
- Events accept_all/reject_all/custom/withdraw → Task 9 `classifyEvent` + Task 6 whitelist. ✓
- Anonymous consent ID embedded in one cookie → Task 9 (`cid` in banner + youtube), Task 2 (`getConsentId`). ✓
- Custom DB table created on activation → Task 5 (`createTable`) + Task 7 (activation hook + `maybeUpgrade`). ✓
- Admin tab + CSV export → Task 10. ✓
- Configurable retention default 36 months + daily cron purge → Task 1 (default), Task 4 (`purgeOlderThan`), Task 7 (cron). ✓
- Event flow via REST, best-effort, server-derives policy_version → Task 6 + Task 8 + Task 9. **Refinement vs spec:** the client sends only `{event}`; the server reads `choices` + `cid` from the cookie rather than the request body (stronger integrity; spec §"Event flow" updated to match). ✓
- Backward compatibility (existing cookies without `cid`) → Task 9 (`parseCookie` preserves/ignores `cid`, lazy mint on commit); `isComplete()` already ignores non-service keys (Global Constraints). ✓
- Error handling: never block consent, nonce + rate limit, schema migration, cron resilience, CSV chunking → Tasks 6, 7, 9, 10. ✓
- Testing: unit tests for hashing, insert/conditions/purge, CSV, event validation, rate limit, client config, getConsentId; manual integration for table/REST/admin → Tasks 2–10. ✓

**Placeholder scan:** No TBD/TODO; every code step shows full code. ✓

**Type consistency:** `ConsentLog` method names (`insert`, `query`, `buildConditions`, `purgeOlderThan`, `exportCsv`, `policyVersion`, `hashConfig`, `createTable`, `maybeUpgrade`, `tableName`) are consistent across Tasks 3–7, 10. `RestController::NAMESPACE`/`ROUTE` reused in Task 8. `logConsentEvent`/`ensureConsentId` consistent in Task 9. `SettingsPage` 4-arg constructor introduced in Task 7 and defined in Task 10 — flagged in Task 7 Step 1 to implement 7+10 together. ✓
