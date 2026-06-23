# Design: Reopen cookie preferences ("Gérer les cookies")

**Date:** 2026-06-23
**Plugin:** `lcmt-dev-consent`
**Status:** Approved design — ready for implementation plan

## Background & legal basis

- **RGPD Art. 7(3):** withdrawing consent must be **as easy as giving it**.
- **CNIL *recommandation cookies*:** users must be able to **revisit their choices
  at any time** via a mechanism **permanently available and easily accessible from
  every page** (typically a footer "Gérer les cookies" link).

**Current gap:** the banner only loads while consent is *pending*
([`Assets::enqueue`](../../../src/Frontend/Assets.php) bails once `isComplete`),
and [`Banner::render`](../../../src/Frontend/Banner.php) stops rendering after a
decision. A user who already decided has no way back in — non-compliant with
Art. 7(3).

## Goals

- Let users reopen the **granular preferences panel** on any page, after a decision.
- Preserve the plugin's core promise: **zero banner JS/CSS for decided users**
  until they actually choose to reopen.
- Provide flexible triggers (footer link/selector, JS API, convenience shortcode).
- Reuse the existing commit path so changes/withdrawals are logged and scripts
  update (ties into the consent log + reload-on-change already shipped).

## Non-goals

- No auto-created public "Gestion des cookies" page (admin help only).
- No always-on floating widget (selector/shortcode/API only).
- No change to how consent is stored or how the banner behaves while pending.

## Decisions (from brainstorming)

| Topic | Decision |
|-------|----------|
| Triggers | `.lcmt-open-consent` class **and** `window.lcmtConsent.open()` API **and** `[lcmt_cookies_settings]` shortcode |
| Load strategy | Lazy-load on first trigger (tiny inline opener for decided users) |
| Panel markup source | Server-rendered via a REST route reusing `Banner` markup (PHP single source of truth) |
| Reopen target | The granular **panel** view, pre-filled with current choices |
| Admin help | A new **section in the existing "Politique de confidentialité" tab** (no new tab) |

## Architecture

### Trigger surface
A small **opener script** binds (delegated `click` on `document`) to any element
matching `.lcmt-open-consent`, and exposes `window.lcmtConsent.open()`. The
`[lcmt_cookies_settings]` shortcode outputs
`<button type="button" class="lcmt-open-consent">Gérer les cookies</button>`
(label filterable/translatable).

### Two runtime states

**While consent is pending (unchanged):** the full banner is enqueued and rendered
as today. The opener is part of the banner bundle; `window.lcmtConsent.open()`
simply opens the already-present panel.

**After a decision (new):** `Assets` does **not** enqueue the banner bundle.
Instead a new component outputs:
- a tiny **inline opener script** (a few hundred bytes), and
- a minimal inline config object `window.lcmtConsentReopen` =
  `{ panelUrl, cssUrl, jsUrl }` (REST panel URL + hashed bundle URLs from the
  manifest).

On the **first** trigger activation the opener:
1. Sets a one-shot guard so concurrent/双 clicks load assets once.
2. `fetch`es `GET /wp-json/lcmt-dev-consent/v1/panel` → HTML string; injects it
   into `document.body`.
3. Injects `<link rel="stylesheet" href=cssUrl>` and `<script src=jsUrl>`.
4. When the banner script has booted, calls its real `open()` to show the panel.

Ordering is handled by a queue: the inline opener defines a stub
`window.lcmtConsent = window.lcmtConsent || {}` with an `open()` that records "open
requested"; when `banner.ts` boots it (a) replaces `open()` with the real
implementation and (b) if an open was requested (or it was lazy-loaded), opens the
panel immediately.

### Panel REST route
`GET /wp-json/lcmt-dev-consent/v1/panel` (public, no nonce — it returns only
public markup, no user data) returns the banner HTML produced by a new
`Banner::buildHtml(): string`. `Banner::render()` is refactored to echo
`buildHtml()`; the route returns the same string regardless of consent state.
The `wp_localize_script` config (`window.lcmtConsent` services/texts/etc.) must
also be present for the booted banner — the opener includes it inline (or the
booted JS reads it from a localized blob the opener injects). The opener config
therefore carries the full client config (same array `Assets::clientConfig()`
builds) so the lazy-booted banner behaves identically to the pending-state banner.

### Components

| File | Responsibility |
|------|----------------|
| `src/Frontend/Banner.php` | extract `buildHtml(): string`; `render()` echoes it |
| `src/Frontend/ConsentReopen.php` | decided-state inline opener + `window.lcmtConsentReopen`/`window.lcmtConsent` config; `[lcmt_cookies_settings]` shortcode; `GET …/v1/panel` REST route |
| `src/Frontend/Assets.php` | unchanged enqueue gating; expose `clientConfig()` for reuse (make it accessible to `ConsentReopen`, e.g. move config building to a shared method/class) |
| `assets/src/banner.ts` | real `window.lcmtConsent.open()`; force-open panel when lazy-loaded; honor a queued open request |
| `src/Plugin.php` | wire `ConsentReopen` |

To avoid duplicating `clientConfig()`, factor the client-config array into a
small shared builder (e.g. a static method or a `ClientConfig` helper) used by
both `Assets` and `ConsentReopen`.

## Reopen → change → log (ties to existing features)

When the user changes/withdraws in the reopened panel and clicks OK, the existing
`commit()` runs: it writes the cookie, classifies the event
(`custom`/`withdraw`/`accept_all`/`reject_all`), calls `logConsentEvent()` (the
consent log), and reloads the page when a previously-accepted service was turned
off (`needReload`). No new logic needed — reopen is just another entry into
`commit()`.

## Admin help (existing Privacy tab)

Add a section to `SettingsPage::tab_privacy()`:
- **"Permettre la gestion des cookies"** heading + short note on Art. 7(3).
- The three trigger methods with copy snippets:
  - HTML: `<a href="#" class="lcmt-open-consent">Gérer les cookies</a>`
  - Shortcode: `[lcmt_cookies_settings]`
  - JS: `window.lcmtConsent.open()`
- A live preview of the rendered manage button.

## i18n

New strings ("Gérer les cookies" button label, admin section headings/notes) added
to `languages/lcmt-dev-consent-fr_FR.po`, recompiled to `.mo`. The button label is
translatable and overridable via a shortcode attribute (`label="…"`).

## Error handling & edge cases

- **Double / rapid clicks:** a module-level guard ensures the bundle + markup load
  once; subsequent clicks just call `open()`.
- **JS disabled:** the trigger is an ordinary element; recommend (in admin help)
  also linking it to the privacy-policy/cookie page so there is a non-JS fallback.
- **REST fetch fails:** the opener logs to console (when available) and does
  nothing destructive; the user's existing consent is untouched.
- **Trigger present while consent still pending:** `open()` opens the
  already-rendered panel (no fetch/bundle injection needed).
- **Markup injected twice:** opener checks for an existing `#lcmt-consent` before
  injecting.
- **GTM Consent Mode:** reopening and changing a signal re-runs the existing
  client `gtag('consent','update',…)` path on commit.

## Testing

- **REST panel route:** returns a non-empty HTML string containing the panel
  container (`id="lcmt-consent-panel"`); available regardless of consent state.
- **`Banner::buildHtml()`:** returns markup string (with stubs) containing both
  views; `render()` echoes the same.
- **ConsentReopen config:** the inline config exposes `panelUrl`, `cssUrl`,
  `jsUrl`, and the client config; opener output present only for decided users.
- **Shortcode:** `[lcmt_cookies_settings]` outputs a button with class
  `lcmt-open-consent`; `label` attribute overrides the text.
- **banner.ts:** `window.lcmtConsent.open()` shows the panel (`data-opened`
  toggled) even when consent is complete.
- **Manual:** footer link reopens the panel on a decided session; change a choice
  → consent-log row recorded + refused script removed on reload; visitors who
  never click load no banner bundle.

## Open questions

None blocking.
