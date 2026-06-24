=== LCMT Dev — Consent ===
Contributors: amaurylecomte
Tags: cookies, consent, gdpr, privacy, gtm, google analytics
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later

Lightweight, performance-focused cookie consent banner. JS/CSS are loaded only while consent is pending; accepted services are injected server-side from wp_head afterwards.

== Description ==

* Banner JS/CSS load only on the very first visits (while the user hasn't yet decided).
* Once the user has accepted/refused, scripts for allowed services (GTM, GA4, Facebook Pixel, Matomo) are injected directly in `<head>` — no extra runtime.
* Configurable via Settings → Cookie Consent: texts, position, colors, services.
* Extensible via the `lcmt_dev_consent_services` PHP filter for custom services.
* Polylang / WPML aware — admin-entered strings are auto-registered for translation.

== Developer API ==

Check consent anywhere in your theme/plugin:

`if ( \LcmtDev\Consent\Consent::isAllowed('googletagmanager') ) { /* ... */ }`

Register a custom service:

`add_filter('lcmt_dev_consent_services', function ($services) {
    $services[] = [
        'key'        => 'hotjar',
        'name'       => 'Hotjar',
        'category'   => 'analytic',
        'data'       => ['id' => '000000'],
        'needReload' => true,
        'inject_php' => fn($data) => "<script>/* hotjar snippet for {$data['id']} */</script>",
    ];
    return $services;
});`

Listen for client-side acceptance (first visit only):

`document.addEventListener('lcmt-consent:accepted', (e) => {
    console.log('Accepted:', e.detail.key, e.detail.data);
});`

== Changelog ==

= 1.3.0 =
* New: configurable title icon on the consent banner + panel — choose a built-in cookie icon (default), the 🍪 emoji, a custom image (media library), or none, from Settings → Cookie Consent → Appearance.

= 1.2.0 =
* New: Google Maps consent gate — embedded maps are replaced by a consent placeholder until the visitor accepts (mirrors the YouTube gate). Detects raw Google Maps iframes in content/widgets and theme templates via `GoogleMapsEmbed::render()`.
* Change: YouTube and Google Maps services now ship disabled by default (opt-in from Settings → Cookie Consent → Services).
* Fix: the "Gérer les cookies" reopen panel now renders in the current page language on multilingual sites instead of the site default.
* Build: `npm run package` produces a clean, upload-ready plugin zip (dev files excluded).

= 1.0.0 =
* Initial release.
