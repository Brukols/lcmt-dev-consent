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
│   │   └── ScriptInjector.php
│   ├── Admin/
│   │   ├── SettingsPage.php
│   │   ├── Settings.php
│   │   └── Translations.php
│   └── Services/
│       ├── ServiceRegistry.php
│       └── Service.php
└── assets/
    ├── src/                      # NOT shipped to prod (excluded in rsync)
    │   ├── banner.ts
    │   ├── injectors.ts
    │   ├── types.ts
    │   └── banner.scss
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

## Settings cache
`Settings::all()` caches the merged option+defaults in an instance `$cache` property so repeated reads within a request don't re-parse the serialized array. Invalidated by `save()` and `bumpConsentVersion()`.

## ServiceRegistry cache
`ServiceRegistry::all()` caches the computed list of `Service` value objects in `$services` so all three front-end hooks share the same list without re-running the filter pipeline.
