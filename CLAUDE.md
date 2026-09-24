# `lcmt-dev-consent` — Claude Instructions

## Auto-Update Rule
**Always keep the `.claude/` files up to date.** When you change something in the plugin that affects the information documented in `.claude/` (new class, new setting, new hook, renamed service, altered data flow, new admin tab, etc.), update the relevant file in the same commit. If you discover something new, add it; if something documented is wrong, fix it. Documentation maintenance is part of every task.

## Plugin summary
Performance-focused cookie consent banner for WordPress. Ships its own PHP classes (PSR-4-ish autoload under `LcmtDev\Consent\`), admin options page, and a compiled TS/SCSS banner. Banner JS/CSS are enqueued **only while consent is pending**; once the user has decided, accepted services are injected server-side in `wp_head` on every subsequent request with no runtime JS/CSS from this plugin.

- **Slug / text domain:** `lcmt-dev-consent`
- **PHP namespace:** `LcmtDev\Consent\…`
- **Main file:** [lcmt-dev-consent.php](lcmt-dev-consent.php)
- **Single option row:** `lcmt_dev_consent_settings` (autoloaded, serialized)
- **Cookie name:** configurable, defaults to `cookieConsent`; bumped to `cookieConsent_v{N}` when admin clicks "Reset all user consents"
- **Default CSS-variable prefix:** `--lcmt-consent-*`
- **JS global:** `window.lcmtConsent` (localized config)

## Documentation files
- [.claude/architecture.md](.claude/architecture.md) — directory layout, PHP classes, boot sequence, request-time data flow
- [.claude/admin-settings.md](.claude/admin-settings.md) — Settings API tabs, options schema, per-tab save, sanitization
- [.claude/frontend.md](.claude/frontend.md) — banner TS + SCSS, build toolchain, CSS-variable theming, `!important` rationale
- [.claude/services.md](.claude/services.md) — predefined services, Google Consent Mode v2 flow, client/server injectors
- [.claude/releasing.md](.claude/releasing.md) — GitHub releases, update checker, how to publish a version
- [.claude/extending.md](.claude/extending.md) — developer API: `lcmt_dev_consent_services` filter, `Consent::isAllowed()`, `lcmt-consent:accepted` CustomEvent, Polylang/WPML integration

## Key files (quick reference)
| File | Purpose |
|------|---------|
| `lcmt-dev-consent.php` | Plugin header, autoloader, boot on `plugins_loaded` |
| `src/Updater.php` | Update checker wired to the GitHub releases (`lib/plugin-update-checker/`, vendored) |
| `.github/workflows/release.yml` | Builds `lcmt-dev-consent.zip` and publishes the release on a `v*` tag |
| `src/Plugin.php` | Wires all components together |
| `src/Consent.php` | Public `isAllowed()` / `getCookies()` / `isComplete()` API |
| `src/Admin/Settings.php` | Option accessor, defaults, predefined-service metadata |
| `src/Admin/SettingsPage.php` | Settings → Cookie Consent (5 tabs), per-tab sanitization |
| `src/Admin/Translations.php` | Polylang/WPML registration + WP `.mo` fallback |
| `src/Services/ServiceRegistry.php` | Merges UI services + filter-registered services, GTM Consent Mode virtual services |
| `src/Services/Service.php` | Value object passed to injectors |
| `src/Frontend/Assets.php` | Conditional enqueue (only while consent pending) |
| `src/Frontend/Banner.php` | Renders banner HTML + CSS-variable `<style>` block in `wp_footer` |
| `src/Frontend/ScriptInjector.php` | Server-side injection in `wp_head` for accepted services + Consent Mode defaults + GTM loader |
| `src/Integrations/SiteKit.php` | Google Site Kit detection; blocks its tag until consent (Consent Mode v2 basic/advanced) |
| `assets/src/banner.ts` | Main banner class |
| `assets/src/injectors.ts` | Client-side injectors (first-accept in same session) |
| `assets/src/banner.scss` | Styles, CSS variables on `:root`, BEM class names |
| `webpack.config.js` | esbuild-loader + sass-loader + manifest plugin |
| `languages/lcmt-dev-consent-fr_FR.po/.mo` | French translations |

## Lessons learned
- **CSS variables must live on `:root`**, not on `.lcmt-consent`. If defaults are placed on `.lcmt-consent`, they out-specify the PHP-emitted `:root { --… }` block and prevent admin colors from applying.
- **Theme button resets** (`button { background-color: transparent }`) have the same specificity as plugin button classes and load later. `!important` on accent background/color is warranted — documented inline in `banner.scss`.
- **GTM Consent Mode v2** is fundamentally different from the standard "load on accept" model: GTM always loads, gtag defaults all signals to denied, and each accept fires a `gtag('consent','update',…)` — see [.claude/services.md](.claude/services.md).
- **Google Site Kit** is never reconfigured: its tag is gated through its own `googlesitekit_{module}_tag_blocked` filters, so deactivating this plugin restores Site Kit as it was — see [.claude/services.md](.claude/services.md#google-site-kit).
- **Per-tab saves** are critical: the admin form must submit only the current tab's slice of settings, otherwise saving one tab wipes others. See `SettingsPage::sanitizeForTab()`.
- **Never change the slug** (`lcmt-dev-consent` folder, main file, text domain): the update checker and WordPress identify the plugin by it. Only the displayed name is "LCMT Consent".
- **Autoloaded option** gives zero extra DB queries per request on the front-end.
