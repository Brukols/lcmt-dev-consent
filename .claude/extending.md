# Extending the plugin

## Check consent anywhere in PHP
```php
use LcmtDev\Consent\Consent;

if (Consent::isAllowed('googletagmanager')) {
    // user accepted GTM — do the thing
}

$all = Consent::getCookies();   // ['googletagmanager' => 'true', 'matomo' => 'false', …]
Consent::isComplete(['googletagmanager', 'matomo']); // bool
```
These are static methods; no class instantiation needed. Cookie name defaults to `cookieConsent` — pass a custom name as the second arg if you've changed it in settings.

## Consent log (proof of consent)
Each consent action is recorded server-side in `wp_lcmt_consent_log` for RGPD
Art. 7 / CNIL proof (see [architecture.md](architecture.md) for the full design).

- The consent cookie carries an anonymous id as a `cid=<uuid>` pair. Read it with
  `Consent::getConsentId($cookieName)` (returns `null` if absent).
- If you build a **custom banner** instead of the bundled one, log events by
  POSTing to the REST route after you write the cookie (incl. `cid`):
  ```js
  fetch(window.lcmtConsent.log.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.lcmtConsent.log.nonce },
      credentials: 'same-origin',
      body: JSON.stringify({ event: 'accept_all' }), // accept_all|reject_all|custom|withdraw
  });
  ```
  The server reads `choices` + `cid` from the cookie and derives the policy/cookie
  versions itself — `event` is the only body field. Logging is best-effort; never
  block the user's choice on it. The endpoint + nonce are exposed in
  `window.lcmtConsent.log` (`{enabled, endpoint, nonce}`).

## Reopen the preferences panel ("Gérer les cookies")
Required by RGPD Art. 7(3) — let users change/withdraw consent any time. Three
equivalent triggers, all reopen the granular panel (works even after a decision,
lazy-loading the banner bundle on first use):

- **Shortcode:** `[lcmt_cookies_settings]` → `<button class="lcmt-open-consent">Gérer les cookies</button>`. Override the label: `[lcmt_cookies_settings label="Préférences cookies"]`.
- **Any element** with `class="lcmt-open-consent"` (e.g. a footer custom-link menu item): `<a href="#" class="lcmt-open-consent">Gérer les cookies</a>`.
- **JS API:** `window.lcmtConsent.open()`.
- **Menu / link URL:** any link whose fragment is `#cookie-settings` (anchor `.hash === '#cookie-settings'`). In Appearance → Menus add a **Custom Link** with URL `#cookie-settings` — no CSS class needed.

See [frontend.md](frontend.md) for the lazy-load mechanism and the
`GET /wp-json/lcmt-dev-consent/v1/panel` route.

## Cookie information table (`[lcmt_cookies_table]`)
Renders a CNIL-style table (Service · Cookie · Purpose · Retention · Issuer) of the
cookies used by the **enabled** services, for the privacy-policy page.

- Place `[lcmt_cookies_table]` on any page/post. Add `essential="0"` to hide the
  plugin's own consent-cookie row: `[lcmt_cookies_table essential="0"]`.
- Data comes from `Settings::defaultServiceCookies()` (shipped), overridden per
  service in Settings → Cookie Consent → Services, or supplied for code services
  via the filter below. See [services.md](services.md) for the resolution order.
- The admin "Politique de confidentialité" tab shows the shortcode + a live preview.

To attach cookie rows to a **code-registered service**, add a `cookies` key:
```php
add_filter('lcmt_dev_consent_services', function (array $services) {
    $services[] = [
        'key' => 'hotjar',
        'name' => 'Hotjar',
        'category' => 'analytic',
        'cookies' => [
            [
                'name' => '_hjSessionUser_*',
                'purpose' => 'Stores a unique Hotjar user ID for session replay and heatmaps.',
                'retention' => '12 months',
                'issuer' => 'Hotjar',
                'third_party' => true,
                'url' => 'https://www.hotjar.com/legal/policies/privacy/',
            ],
        ],
        // … inject_php etc. as usual
    ];
    return $services;
});
```
Cookie rows are **server-only** — never exposed in `window.lcmtConsent`.

## Register a custom service from code
```php
add_filter('lcmt_dev_consent_services', function (array $services) {
    $services[] = [
        'key'        => 'hotjar',
        'name'       => 'Hotjar',
        'description' => 'Session replay and heatmaps.',
        'category'   => 'analytic',       // must exist in Categories tab
        'uri'        => 'https://www.hotjar.com/legal/policies/privacy/',
        'data'       => ['id' => '000000'],
        'needReload' => false,            // true = reload after accept, skip client injector
        'inject_php' => function (array $data): string {
            $id = esc_js($data['id']);
            return "<script>/* Hotjar snippet using id={$id} */</script>";
        },
    ];
    return $services;
});
```

### What each field does
- `key` — unique identifier used in the cookie. Keep it lowercase, no spaces.
- `name` / `description` — user-facing strings shown in the banner (both pass through the translation layer).
- `category` — must match one of the admin-defined category keys (`api`, `analytic`, `ads`, or any custom one added via the Categories tab).
- `data` — whatever the injector needs at runtime. Passed as the only argument to `inject_php`.
- `needReload` — true = reload the page after commit (server-side `inject_php` will then run). Use when your script must be present from page start (early hooks, before DOMContentLoaded, etc.).
- `inject_php` — callable returning `<script>` HTML. Runs in `wp_head` on every request where the cookie has this key at `=true`.

## Client-side hook for custom services
Built-in predefined services (GTM, GA4, FB Pixel, Matomo + 4 Google Consent Mode signals) have built-in client injectors. Filter-registered services do **not** — by design, we don't `eval` arbitrary strings from PHP in the browser.

Two ways to run client-side code on acceptance:
1. **`needReload: true`** — simplest; page reloads after accept, `inject_php` handles everything server-side.
2. **Listen for the CustomEvent**:
   ```js
   document.addEventListener('lcmt-consent:accepted', (e) => {
       const { key, data } = e.detail;
       if (key === 'hotjar') {
           // run your custom injection using data.id
       }
   });
   ```
   Fires once per newly-accepted service during `commit()`.

## Translate user-facing strings
Three tiers, in priority order:

1. **Polylang / WPML** string translation — every admin-entered string is auto-registered under context `lcmt-dev-consent` on `init` and after saves. Per-language translations available in their UIs.
2. **WP native `translate()`** — reads `languages/lcmt-dev-consent-{locale}.mo`. Ship a `.po` + compiled `.mo` for each target locale. The French one is included (`lcmt-dev-consent-fr_FR.po/.mo`).
3. **`apply_filters('lcmt_dev_consent_string', $value, $name)`** — code-based override. Every rendered string passes through this, named by dot path (e.g. `texts.title`, `categories.api.name`, or empty string for "passthrough" strings like service descriptions).

To translate a built-in English source string (e.g. "Used to track user actions on the site."), add it to your `.po` file:
```po
msgid "Used to track user actions on the site."
msgstr "Utilisé pour suivre les actions des utilisateurs sur le site."
```
Then compile with `msgfmt file.po -o file.mo`.

## Regenerate the French `.mo`
```bash
cd wp-content/plugins/lcmt-dev-consent/languages
msgfmt lcmt-dev-consent-fr_FR.po -o lcmt-dev-consent-fr_FR.mo
```
Do this whenever you edit the `.po`. Commit both files.

## Embed a YouTube video with consent gating
For consent-aware YouTube embeds, theme/plugin code can call:
```php
echo \LcmtDev\Consent\Frontend\YouTubeEmbed::render($urlOrId);
```
- Accepts a bare 11-char video ID or any `youtube.com` / `youtu.be` / `youtube-nocookie.com` URL.
- Returns the real `<iframe>` if consent is granted, otherwise a styled placeholder with an inline "Accept and play" button.
- Returns an empty string if the input doesn't parse as a recognizable video ID — wrap your call in an `if` accordingly.

Pasting a YouTube URL into a Gutenberg block or a Classic Editor paragraph works too — `YouTubeEmbed` filters `wp_oembed_get_html` and `render_block` so any YouTube embed on the site is gated automatically. No theme changes needed for editor-driven content.

## Embed a Google Maps map with consent gating
For consent-aware Google Maps embeds, theme/plugin code can call:
```php
echo \LcmtDev\Consent\Frontend\GoogleMapsEmbed::render($embedUrl, $height);
```
- Accepts any Google Maps embed URL (`www.google.<tld>/maps/embed?…`, `/maps/d/embed?…`, or `maps.google.com/maps?…&output=embed`); `$height` is optional (default 450).
- Returns the real `<iframe>` if consent is granted, otherwise a placeholder with an inline "Accept and display" button. Returns an empty string if the URL isn't a recognizable Maps URL.

Pasting a Maps iframe into a Custom HTML block (Gutenberg) or raw HTML (Classic Editor) works too — `GoogleMapsEmbed` filters `the_content` and `widget_text`, so any Google Maps iframe on the site is gated automatically. No theme changes needed for editor-driven content.

## Adding a new predefined service
If a service is common enough to warrant first-class admin UI:
1. Add the defaults entry to `Settings::defaults()['services']` (enabled/id/category/display_name).
2. Add metadata to `Settings::predefinedServiceMeta()` (name, description, uri, id_fields).
3. Add a `case` to `ServiceRegistry::builtinInjectPhp()` returning the `<script>` string.
4. Add a corresponding entry to `INJECTORS` in `assets/src/injectors.ts`.
5. Add French translations for the name + description to `languages/lcmt-dev-consent-fr_FR.po`, recompile `.mo`.
6. `npm run build` to produce a new `banner.[hash].js`.
