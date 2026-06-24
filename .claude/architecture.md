# Architecture

## Directory layout
```
lcmt-dev-consent/
├── lcmt-dev-consent.php         # Plugin bootstrap (header + autoload + init)
├── uninstall.php                 # delete_option on plugin uninstall
├── readme.txt
├── package.json                  # JS build deps
├── webpack.config.js
├── tsconfig.json
├── .gitignore                    # ignores node_modules/
├── CLAUDE.md
├── .claude/                      # ← you are here
├── languages/
│   ├── lcmt-dev-consent-fr_FR.po
│   └── lcmt-dev-consent-fr_FR.mo # compiled with msgfmt
├── src/
│   ├── Plugin.php                # Main composition root
│   ├── Consent.php               # Cookie read/parse/isAllowed (public API)
│   ├── Frontend/
│   │   ├── Assets.php
│   │   ├── Banner.php
│   │   ├── ScriptInjector.php
│   │   ├── YouTubeEmbed.php      # per-embed YouTube consent gate
│   │   └── GoogleMapsEmbed.php   # per-embed Google Maps consent gate
│   ├── Admin/
│   │   ├── SettingsPage.php
│   │   ├── Settings.php
│   │   └── Translations.php
│   ├── Log/
│   │   ├── ConsentLog.php       # consent_log table, insert/query/purge, CSV export
│   │   └── RestController.php   # POST lcmt-dev-consent/v1/log
│   └── Services/
│       ├── ServiceRegistry.php
│       └── Service.php
└── assets/
    ├── src/                      # NOT shipped to prod (excluded in rsync)
    │   ├── banner.ts
    │   ├── injectors.ts
    │   ├── types.ts
    │   ├── banner.scss
    │   ├── youtube.ts / youtube.scss        # YouTube placeholder upgrader
    │   └── googlemaps.ts / googlemaps.scss   # Google Maps placeholder upgrader
    └── dist/                     # Committed; shipped to prod
        ├── banner.[hash].js
        ├── banner.[hash].css
        └── manifest.json         # PHP reads this to find hashed filenames
```

## Autoloader
Defined inline in `lcmt-dev-consent.php`. Strips the `LcmtDev\Consent\` prefix, maps backslashes to slashes, loads from `src/`. No Composer needed.

## Boot sequence (`plugins_loaded` action)
```
load_plugin_textdomain('lcmt-dev-consent', …, 'languages');
(new Plugin)->boot();
```

`Plugin::boot()`:
1. Instantiates `Settings` (lazy-loads the single autoloaded option row).
2. Instantiates `ServiceRegistry` (lazy — services list built on first `all()` call).
3. Instantiates `Translations` and calls `register()`, which hooks into `init` and `update_option_lcmt_dev_consent_settings` to feed admin-entered strings to Polylang/WPML if active.
4. If `is_admin()`: registers `SettingsPage` (admin menu, save handler, asset enqueue for color picker).
5. Always registers `Assets`, `Banner`, and `ScriptInjector` — each decides internally whether to output anything based on consent state.

## Request-time data flow (front-end, non-admin)

Three hooks, all reading the same `$_COOKIE[<cookieName>]`:

### 1. `wp_head` priority 1 — `ScriptInjector::inject()`
- If GTM Consent Mode v2 is enabled: emit `gtag('consent','default', {…})` with state from cookie (defaulting to "denied"), then the GTM loader — **unconditionally**.
- Then iterate `ServiceRegistry::all()` and for every service where the cookie says `=true`, call its `injectPhp` callable and echo the returned HTML (typically a `<script>` tag).

### 2. `wp_enqueue_scripts` — `Assets::enqueue()`
- Reads the manifest from `assets/dist/manifest.json` to resolve hashed filenames.
- Enqueues `banner.[hash].css` + `banner.[hash].js` **only if consent is incomplete** (any service still at `wait`).
- Calls `wp_localize_script` with the full config (services, categories, texts, cookie settings) into `window.lcmtConsent`.

### 3. `wp_footer` priority 5 — `Banner::renderStyleVars()`
Always runs if enabled. Emits a single `<style id="lcmt-consent-vars">:root{--lcmt-consent-bg:…; …}</style>` block plus any custom CSS from Advanced tab. Cheap (inline string) and required even when the banner itself isn't rendered so that returning-visitor UI elements respecting the vars still render correctly if any.

### 4. `wp_footer` priority 20 — `Banner::render()`
- Only renders if `shouldRender()` returns true (banner enabled, at least one service configured, consent still incomplete).
- Outputs the banner HTML with two views (`#lcmt-consent-main`, `#lcmt-consent-panel`), service list grouped by category.

## Consent log ("registre / preuve de consentement")

Server-side, auditable proof of consent (RGPD Art. 7 / CNIL). Added alongside the
client-only cookie; the cookie remains the runtime source of truth for what to
inject, the log is the audit trail.

- **Table:** `{$wpdb->prefix}lcmt_consent_log` — columns `id`, `consent_id`
  (anonymous UUID), `event` (`accept_all|reject_all|custom|withdraw`), `choices`
  (JSON of service→bool), `cookie_version` (`consent_version` int), `created_at`
  (UTC). **No IP / identity stored.** DB schema **v3** (`maybeUpgrade()` recreates
  the table; the schema bump also drops the removed `lcmt_consent_policies` table).
- **Schema lifecycle:** created on `register_activation_hook` via `dbDelta`;
  `ConsentLog::maybeUpgrade()` (hooked on `admin_init`) re-runs `createTable()`
  when the stored `lcmt_dev_consent_db_version` option differs from
  `ConsentLog::DB_VERSION`. Dropped in `uninstall.php`.
- **Anonymous id:** the banner embeds `cid=<uuid>` as an extra pair in the
  consent cookie (e.g. `!googleanalytics=true!cid=…`). `Consent::getConsentId()`
  reads it; `isComplete()`/`isAllowed()` ignore it (they only test service keys).
  Minted lazily on first commit / first YouTube accept — legacy cookies without a
  `cid` keep working unchanged.
- **REST route:** `POST lcmt-dev-consent/v1/log` (`RestController`). Permission =
  valid `wp_rest` nonce (`X-WP-Nonce` header). Body carries **only** `{event}`;
  the server reads `choices` + `cid` from the cookie sent with the request and
  derives `cookie_version` itself (never trusts the body for it). Per-IP transient
  rate limit (30 req / 60 s; IP hashed into the transient
  key, never persisted). Best-effort: a failed/blocked request never blocks the
  client-side cookie write.
- **Frontend trigger:** `assets/src/consent-log.ts` (`logConsentEvent` +
  `ensureConsentId`); called from `banner.ts::commit()` (event classified by
  before/after state) and `youtube.ts` (placeholder accept → `custom`).
- **Retention cron:** daily `lcmt_dev_consent_purge` event (scheduled on
  activation + re-ensured on `init`) calls `ConsentLog::purgeOlderThan(months)`
  with `log_retention_months` (default 36). Unscheduled on deactivation/uninstall.
- **Admin:** `SettingsPage` "Registre de consentement" tab — retention settings,
  paginated/filterable record table, CSV export (`handleExport()`).

## Settings cache
`Settings::all()` caches the merged option+defaults in an instance `$cache` property so repeated reads within a request don't re-parse the serialized array. Invalidated by `save()` and `bumpConsentVersion()`.

## ServiceRegistry cache
`ServiceRegistry::all()` caches the computed list of `Service` value objects in `$services` so all three front-end hooks share the same list without re-running the filter pipeline.
