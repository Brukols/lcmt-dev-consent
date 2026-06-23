# Design: Cookie information table shortcode + privacy tab

**Date:** 2026-06-23
**Plugin:** `lcmt-dev-consent`
**Status:** Approved design — ready for implementation plan

## Background & legal basis

The CNIL requires that users be **informed** about the trackers used: for each
cookie/tracer, its **purpose (finalité)**, **retention (durée de conservation)**,
and the **identity of the entity** depositing it. The recommended way to present
this on a *Politique de confidentialité* page is a **cookie table**.

The plugin currently stores **no per-cookie metadata** — services only carry
`key`, `name`, `description`, `category`, `uri`. This feature introduces
cookie-level data and a shortcode that renders the CNIL cookie table, plus an
admin tab to manage and publish it.

## Goals

- A shortcode `[lcmt_cookies_table]` that renders a CNIL-style cookie table.
- Accurate **shipped defaults** for the predefined services, **editable** by the
  admin, and **extensible** by code for custom services.
- List **only enabled services**, so the table reflects what's actually used.
- A new admin tab with **integration instructions + a live preview**.

## Non-goals

- No automatic cookie scanning/detection of the live site.
- No Gutenberg block (shortcode only; works in blocks via the shortcode block).
- No per-cookie consent linkage (consent stays per service/category as today).

## Decisions (from brainstorming)

| Topic | Decision |
|-------|----------|
| Columns | Service, Cookie name, Purpose, Retention, Issuer (name + first/third-party + optional policy link) |
| Data source | Shipped defaults + admin override + code filter |
| Scope | Only enabled services (`ServiceRegistry::all()`) |
| Layout | Single flat table, one row per cookie |
| Admin editing | Full per-row repeatable editor in the Services tab |
| Extra admin tab | "Politique de confidentialité": instructions + live preview |
| Essential row | Include the plugin's own `cookieConsent` cookie by default (toggle via attribute) |
| Shortcode name | `[lcmt_cookies_table]` |

## Data model

One cookie row:

```php
[
    'name'        => '_ga',                       // cookie / tracker name
    // Purpose is a descriptive sentence (CNIL-style), NOT a terse category label:
    'purpose'     => 'Registers a unique ID used to generate statistical data on how the visitor uses the site.',
    'retention'   => '13 months',                 // durée de conservation
    'issuer'      => 'Google',                     // depositing entity
    'third_party' => true,                         // first-party vs third-party
    'url'         => 'https://policies.google.com/privacy', // optional policy link
]
```

**Purpose wording principle:** every shipped default `purpose` is a full,
human-readable sentence describing what the cookie does — not a one-word category.
The English source strings are translated to French via the `.mo`. Example
defaults for Google Analytics (English source → French `.mo`):

| Cookie | Purpose (FR, as shown) | Retention |
|--------|------------------------|-----------|
| `_ga` | Enregistre un identifiant unique utilisé pour générer des données statistiques sur la façon dont le visiteur utilise le site | 13 mois |
| `_gat` (or `_dc_gtm_<property-id>`) | Utilisé pour réduire le taux de requêtes Google Analytics | 1 minute |
| `_gid` | Utilisé pour distinguer les utilisateurs | 1 jour |
| `_ga_<container-id>` | Conserve l'état de session pour Google Analytics 4 | 13 mois |

The same descriptive style applies to the Facebook, Matomo, GTM/Consent-Mode and
YouTube defaults. Services not predefined (e.g. Twitter/X, LinkedIn) are added by
the admin (per-row editor) or via the filter, using the same descriptive wording.

### Storage
- New settings key **`service_cookies`** (in the single `lcmt_dev_consent_settings`
  option): `map<service_key, cookie_row[]>`. Holds **admin overrides only**.
  `Settings::defaults()` adds `'service_cookies' => []`.
- New **`Settings::defaultServiceCookies(): array`** — curated, accurate defaults
  keyed by service key for: `googleanalytics`, `googletagmanager`,
  `facebookpixel`, `matomo`, `youtube`, and the four Consent-Mode signal services
  (`google_analytics_storage`, `google_ad_storage`, `google_ad_user_data`,
  `google_ad_personalization`).
- Code services supply rows via a `cookies` key on the existing
  `lcmt_dev_consent_services` filter. `Service` gains a `cookies` array property
  (default `[]`), populated from the filter args; `toClientConfig()` is unchanged
  (cookies are server-only, never sent to the browser).

### Resolution precedence (per service key)
`Services/CookieRegistry.php` resolves a service's cookies as:
1. **Admin override** — `settings['service_cookies'][key]` when that key is set
   (the admin saved rows for it, possibly an explicit empty list).
2. **Shipped default** — `Settings::defaultServiceCookies()[key]`.
3. **Filter** — `$service->cookies` (code-registered services).

`CookieRegistry` responsibilities:
- `forService(Service $s): array` — resolved, normalized rows for one service.
- `allRows(bool $includeEssential = true): array` — iterate
  `ServiceRegistry::all()` (already enabled-only), attach the service display
  name + category to each row, return a flat list; prepend the essential
  consent-cookie row when requested.
- `normalizeRow(array $row): array` — coerce/trim fields, cast `third_party` to
  bool, validate `url` with `esc_url_raw`-style filtering.
- `essentialRow(): array` — first-party `cookieConsent` (effective name),
  purpose "Stores your cookie consent choices", retention derived from
  `cookie_lifetime_days`, issuer = site host.

## Rendering — `Frontend/CookieTable.php`

- Registers the shortcode on `init`: `add_shortcode('lcmt_cookies_table', …)`.
- Attributes: `essential` (`"1"` default; `"0"` hides the essential row).
- Builds an HTML `<table class="lcmt-cookies-table">` inside a
  `<div class="lcmt-cookies-table-wrap">` (horizontal scroll on small screens),
  columns: Service, Cookie, Purpose, Retention, Issuer. Issuer cell shows
  `Issuer (third-party|first-party)` and links the policy `url` when present
  (`target="_blank" rel="noopener nofollow"`).
- All dynamic values escaped (`esc_html`, `esc_url`). Shipped-default text fields
  run through `Translations::translatePassthrough()` so the `.mo` localizes them;
  admin-entered text passes through unchanged.
- A small scoped stylesheet is emitted **once per page** (guarded by a static
  flag) — the banner CSS is not loaded on the privacy page, so this is
  self-contained. Borders, padding, zebra rows, responsive wrap.
- Empty state: if no enabled services have cookies (and essential is off),
  returns an empty string.

## Admin — Services tab editor

`Admin/SettingsPage.php`:
- Under each predefined service row, a collapsible **repeatable cookie editor**:
  rows of `name | purpose | retention | issuer | third_party (checkbox) | url`,
  pre-filled from the resolved cookies (override → default), with **Add row** /
  **Remove row** buttons (vanilla JS, no dependency).
- Inputs named `lcmt[service_cookies][<key>][<i>][name]`, etc.
- `sanitizeForTab('services')` is extended to parse `service_cookies`: re-index
  rows, drop fully-empty rows, sanitize each field (`sanitize_text_field`,
  `esc_url_raw`, bool for `third_party`). Stored under `service_cookies`.
- Filter-registered (code) services render their cookies **read-only**.

## Admin — "Politique de confidentialité" tab

- New tab `privacy` (label "Politique de confidentialité") added to the tab list
  and `render()`'s `$tabs`.
- Content:
  - **Integration instructions:** the `[lcmt_cookies_table]` shortcode in a
    read-only field with a click-to-copy button, a note on pasting it into the
    privacy-policy page (and the optional `essential="0"` attribute).
  - **Live preview:** the rendered cookie table for the currently enabled
    services (reuses `CookieTable::render()`), shown inside the admin page so the
    admin sees exactly what visitors get.
- Read-only; no settings saved from this tab (so `sanitizeForTab` has no
  `privacy` case).

## i18n

- Shipped-default purpose/retention/issuer strings are English source strings
  rendered through the translation layer; French translations for them are added
  to `languages/lcmt-dev-consent-fr_FR.po` and the `.mo` recompiled.
- New admin labels (tab title, column headers, editor labels, copy button,
  preview heading) added to the `.po`/`.mo`.

## Error handling & edge cases

- Malformed/partial admin rows: empty-name rows are dropped on save; missing
  optional fields render as blank cells.
- A service with no resolved cookies contributes no rows (not an empty group).
- `url` that fails URL validation is stored empty (cell shows issuer text only).
- Shortcode used on a page with zero enabled services → essential row only (or
  empty string if `essential="0"`).
- Stylesheet printed once even if the shortcode appears multiple times on a page.

## Components summary

| File | Responsibility |
|------|----------------|
| `src/Admin/Settings.php` | `service_cookies` default + `defaultServiceCookies()` |
| `src/Services/Service.php` | new `cookies` array property (from filter args) |
| `src/Services/ServiceRegistry.php` | pass `cookies` through from the filter |
| `src/Services/CookieRegistry.php` | resolution precedence, normalization, flat rows, essential row |
| `src/Frontend/CookieTable.php` | `[lcmt_cookies_table]` shortcode + scoped CSS |
| `src/Admin/SettingsPage.php` | per-service cookie editor (Services tab) + Privacy tab (instructions + preview) + `service_cookies` sanitization |
| `languages/*.po/.mo` | French translations for shipped defaults + new admin strings |

## Testing

- **CookieRegistry:** precedence (override > default > filter); `normalizeRow`
  coercion (bool cast, trims, bad url → empty); `allRows` flattens enabled
  services and attaches service name; essential row shape + toggle.
- **CookieTable:** `render()` returns a `<table>` containing expected service +
  cookie cells; `essential="0"` omits the essential row; CSS emitted once.
- **SettingsPage:** `service_cookies` sanitization helper — drops empty rows,
  re-indexes, sanitizes fields, casts `third_party`.
- **Manual:** edit a service's cookies → Save (Services tab) → values persist;
  Privacy tab preview matches; place `[lcmt_cookies_table]` on a page → table
  renders for enabled services; toggling a service updates the table.

## Open questions

None blocking.
