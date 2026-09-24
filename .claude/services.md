# Services — predefined, filter-registered, Consent Mode v2

## Predefined services (admin UI)
Declared in [`Settings::predefinedServiceMeta()`](../src/Admin/Settings.php):

| Key | Default category | ID fields | Client injector | Server injector |
|-----|------------------|-----------|-----------------|-----------------|
| `googletagmanager` | api | `id` (GTM-XXXXXXX) | yes | yes (skipped when Consent Mode v2 is on) |
| `googleanalytics` | analytic | `id` (G-XXXXXXXXX) | yes | yes |
| `facebookpixel` | ads | `id` (Pixel ID) | yes | yes |
| `matomo` | analytic | `url`, `site_id` | yes | yes |
| `youtube` | api | none | n/a | n/a (no global script — see [`YouTubeEmbed`](../src/Frontend/YouTubeEmbed.php)) |
| `googlemaps` | api | none | n/a | n/a (no global script — see [`GoogleMapsEmbed`](../src/Frontend/GoogleMapsEmbed.php)) |

Each predefined service has:
- A built-in PHP injector in [`ServiceRegistry::builtinInjectPhp()`](../src/Services/ServiceRegistry.php) that returns a `<script>…</script>` string on every request where the cookie has this service at `=true`.
- A built-in TypeScript injector in [`assets/src/injectors.ts`](../assets/src/injectors.ts) that fires once when the user first accepts (same session, no reload needed).

## Service resolution
`ServiceRegistry::all()` produces the effective list:
1. UI-configured services: one entry per admin-enabled service with non-empty required ID(s).
2. Consent Mode v2 virtual services (see below): added when GTM has `consent_mode` = true.
3. Filter-registered services: `apply_filters('lcmt_dev_consent_services', [])`. Skipped if the key is already present from step 1/2 (UI/built-in wins, so code can register defaults the admin is allowed to override).

Result is cached on the instance.

## Google Consent Mode v2 flow

Triggered by the **Use Google Consent Mode v2** checkbox on the GTM row (Services tab), or automatically when Google Site Kit is detected (see [Google Site Kit](#google-site-kit) — same virtual services and defaults, no GTM loader). `ServiceRegistry::isConsentMode()` covers both; `isGtmConsentMode()` is the GTM-only case. When GTM Consent Mode is active:

### 1. GTM is pulled out of the user-facing list
`ServiceRegistry::all()` skips the `googletagmanager` key when building UI services. GTM won't show up as a banner toggle; it always loads.

### 2. Virtual services are added
Four synthetic services are injected (see `Settings::consentModeServices()`):

| Service key | Google signal | Category |
|-------------|---------------|----------|
| `google_analytics_storage` | `analytics_storage` | analytic |
| `google_ad_storage` | `ad_storage` | ads |
| `google_ad_user_data` | `ad_user_data` | ads |
| `google_ad_personalization` | `ad_personalization` | ads |

Each has a name + description that run through the translation layer (English defaults + French translations in `.po`/`.mo`).

### 3. Server-side snippet (wp_head, priority 1)
`ScriptInjector::emitConsentModeSnippet()` emits, on every request:
```html
<script>
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function(){window.dataLayer.push(arguments);};
    gtag('consent', 'default', {
        analytics_storage: "<granted|denied from cookie>",
        ad_storage: "<granted|denied from cookie>",
        ad_user_data: "<granted|denied from cookie>",
        ad_personalization: "<granted|denied from cookie>",
    });
</script>
<script>/* GTM loader for the configured GTM ID */</script>
```
All 4 signals default to `"denied"` if the cookie isn't set yet. Returning visitors get `"granted"` values straight from the initial call, so GTM never sees a denied→granted flip on the server render.

### 4. Client-side accept (first visit)
`assets/src/injectors.ts` defines 4 entries that call `window.gtag('consent', 'update', { <signal>: 'granted' })`. The `gtag` global was defined in step 3, so GTM reacts live to the update — no reload required.

### Turning Consent Mode v2 OFF
If the admin unchecks the box, `isGtmConsentMode()` returns false. GTM becomes a normal per-service toggle again: the built-in injector in `ServiceRegistry::builtinInjectPhp('googletagmanager')` takes over, and the 4 virtual services disappear. The default server-side snippet is not emitted.

## Google Site Kit

[`Integrations\SiteKit`](../src/Integrations/SiteKit.php). Site Kit prints its Google tag on every page regardless of consent; when it does, the plugin puts that tag under Consent Mode v2 **without changing any Site Kit setting**.

### Detection (`SiteKit::isDetected()`)
All of: `GOOGLESITEKIT_VERSION` defined, `analytics-4` in the `googlesitekit_active_modules` option, `useSnippet` true in `googlesitekit_analytics-4_settings`, and a valid tag ID. `tagId()` = `googleTagID` (GT-…) when set, else `measurementID` (G-…). Result cached per request.

### What detection turns on
- `ServiceRegistry::isConsentMode()` → true: the 4 virtual signal services appear in the banner and `ScriptInjector` prints the `gtag('consent','default', …)` snippet at `wp_head` priority 1, before Site Kit's tag. **No GTM loader** (that stays GTM-only).
- A UI `googleanalytics` service with the **same ID** as Site Kit is dropped (would double-count).
- `SiteKit::register()` hooks `googlesitekit_{analytics-4|ads|tagmanager}_tag_blocked`.

### Basic mode (default)
- `filterTagBlocked()` blocks Site Kit's tags until the cookie has **at least one signal granted** → nothing reaches Google before consent.
- Signal services carry `data.sitekit_id`. On first accept, `injectors.ts` sends each `consent update` then (deferred with `setTimeout(0)`, once per page) loads `gtag/js?id=<sitekit_id>` + `gtag('js')` + `gtag('config')` — so every signal of the same commit is updated before the config runs. No reload.
- Later pages: the tag is no longer blocked, **Site Kit prints its own tag** (with its conversion events, linker, custom dimensions) after our defaults.

### Advanced mode (`sitekit_advanced` setting)
Site Kit's tag is never blocked; it loads with every signal denied by default and Google gets cookieless pings (modelling). Signal services have no `sitekit_id` (the tag is already there; the updates alone flip it live). The admin text warns that the CNIL treats this as collection without consent.

### Site Kit's own Consent Mode setting
If enabled in Site Kit, it prints a second, all-denied `consent default` **after** ours. For returning visitors, `ScriptInjector` therefore repeats granted signals as `gtag('consent','update', …)` — gtag resolves each signal as update-over-default, whatever the order (verified in Chrome: `google_tag_data.ics.entries.analytics_storage = {default:false, update:true}` → granted).

### Limits
- Site Kit's `_googlesitekit.gtagEvent` conversion events are lost on the single page where the visitor first accepts (our minimal tag), and before consent in basic mode.
- AdSense (`googlesitekit_adsense_tag_blocked`) is not handled.
- Testing locally: Site Kit only prints tags in the `production` environment and when the module is "connected" (`accountID`, `propertyID`, `webDataStreamID`, `measurementID` set). Use a throwaway plugin adding the `googlesitekit_allowed_tag_environment_types` filter, fake IDs in the option, and **never the client's real tag ID** (hits would reach their property).

## YouTube — per-embed gating

Unlike the analytics services, `youtube` has no single global script. Each embed is its own iframe instance, so consent is enforced per-embed via [`Frontend\YouTubeEmbed`](../src/Frontend/YouTubeEmbed.php). It hooks:

- `wp_oembed_get_html` — replaces the iframe for any `youtube.com` / `youtu.be` / `youtube-nocookie.com` URL when consent is missing.
- `render_block` (`core/embed` with `providerNameSlug: youtube|youtube-shorts`, plus legacy `core-embed/youtube`).
- `YouTubeEmbed::render($urlOrId)` — public static helper for theme/plugin code (used by the Air Sceno theme's product page).

When consent IS granted, all three return the real `<iframe>` server-side; no client-side JS runs. When consent is missing, they return a styled placeholder with `data-lcmt-youtube-id="…"` and an "Accept and play" button. The placeholder is upgraded by [`assets/src/youtube.ts`](../assets/src/youtube.ts), enqueued only when `youtube` service is enabled and not yet accepted (see `Assets::enqueueYoutube()`). One accept upgrades all placeholders on the page via the `lcmt-consent:accepted` event.

If the admin toggles the YouTube service OFF in Settings → Cookie Consent → Services, `YouTubeEmbed` becomes a passthrough — original embeds render unchanged.

## Google Maps — per-embed gating

`googlemaps` works like `youtube` (per-embed, no global script) but with a different detection strategy: **Google Maps is not a WordPress oEmbed/block provider** — maps are pasted as raw `<iframe src="https://www.google.com/maps/embed?…">` (a Custom HTML block in Gutenberg, or raw HTML in the Classic editor). Both end up in the post body, so [`Frontend\GoogleMapsEmbed`](../src/Frontend/GoogleMapsEmbed.php) hooks `the_content` (priority 20, after `do_blocks`/`wpautop`) and `widget_text`, scans the rendered HTML for Google-Maps iframes, and swaps each one.

- Recognized hosts/paths: `www.google.<tld>/maps/...` (incl. `/maps/embed`, `/maps/d/embed`) and `maps.google.com/maps?…&output=embed` (see `GoogleMapsEmbed::isMapsUrl()`). Non-Maps iframes (YouTube, reCAPTCHA, etc.) pass through untouched.
- `GoogleMapsEmbed::render($src)` — public static helper for theme/plugin code.

When consent IS granted the original iframe passes through (server-side); no client JS runs. When consent is missing each iframe is replaced by a placeholder carrying `data-lcmt-maps-src="…"` + `data-lcmt-maps-height="…"` (the full embed URL and height, so the exact iframe can be rebuilt) and an "Accept and display" button. The placeholder is upgraded by [`assets/src/googlemaps.ts`](../assets/src/googlemaps.ts), enqueued only when `googlemaps` is enabled and not yet accepted (see `Assets::enqueueGooglemaps()`). One accept upgrades all placeholders on the page via the `lcmt-consent:accepted` event. Toggling the service OFF makes the filter a passthrough.

## Custom services (via filter)
Developers register additional services with `add_filter('lcmt_dev_consent_services', fn($services) => $services)`. See [extending.md](extending.md) for the full shape.

Filter-registered services appear as a **read-only table** on the Services admin tab (under "Services registered via code") — cannot be edited from the UI. Their category must either be a default (`api|analytic|ads`) or a custom category the admin has added via the Categories tab, otherwise the banner won't render them under any section.

## Cookie metadata (CNIL cookie table)

Per-cookie data powers the `[lcmt_cookies_table]` shortcode (see
[extending.md](extending.md)) and the "Politique de confidentialité" admin tab.
**Server-only — never sent to `window.lcmtConsent`.**

- A cookie row = `['name','purpose','retention','issuer','third_party'(bool),'url']`.
  `purpose` is a full descriptive sentence (English source, localized via `.mo`).
- **Shipped defaults:** [`Settings::defaultServiceCookies()`](../src/Admin/Settings.php)
  keyed by service key (GA4, GTM, Facebook, Matomo, YouTube + the 4 Consent-Mode
  signal services).
- **Admin overrides:** stored in the `service_cookies` option key, edited via the
  repeatable per-service editor on the Services tab.
- **Code services:** supply rows via a `cookies` key on the `lcmt_dev_consent_services`
  filter (carried on `Service::$cookies`).
- **Resolution** ([`Services/CookieRegistry.php`](../src/Services/CookieRegistry.php)):
  `forService()` = admin override → shipped default → filter cookies, each row
  normalized (`normalizeRow()` trims, casts `third_party`, blanks non-http URLs).
  `allRows(bool $includeEssential=true)` flattens the enabled services
  (`ServiceRegistry::all()`), attaching the service display name, and prepends
  `essentialRow()` (the plugin's own `cookieConsent` cookie, first-party,
  retention from `cookie_lifetime_days`).
- **Rendering:** [`Frontend/CookieTable.php`](../src/Frontend/CookieTable.php) —
  `[lcmt_cookies_table]` (attr `essential="0"` hides the essential row); emits a
  scoped `<style>` once per request.

## Service value object
[`Service.php`](../src/Services/Service.php) holds:
- `key`, `name`, `description`, `category`, `uri`
- `cookies` — array of cookie-metadata rows (from the filter args); server-only, used by the cookie table. Default `[]`.
- `data` — array passed to both client and server injectors (e.g. `['id' => 'GTM-XXXXXXX']`)
- `needReload` — when true, the banner reloads after commit instead of running the client injector
- `injectPhp` — PHP callable for server-side wp_head injection
- `source` — `'ui'` or `'code'`
- `toClientConfig()` — stripped representation sent to `window.lcmtConsent.services` (no PHP callables)
