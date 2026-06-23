# Admin — Settings page

Location: **Settings → Cookie Consent** (`options-general.php?page=lcmt-dev-consent`).

## Stack
- Pure WordPress Settings API (no React).
- Color fields use the built-in `wp-color-picker` (jQuery).
- Inline stylesheet in `admin_enqueue_scripts` for the tab chrome + services table.

## Tabs
| Slug | Method | Scope |
|------|--------|-------|
| `general` | `tab_general()` | `enabled`, `position`, `privacy_url`, `texts.*` |
| `appearance` | `tab_appearance()` | `appearance.*` (colors + radius + z-index) |
| `services` | `tab_services()` | `services.*` (predefined service config + GTM Consent Mode v2 checkbox) |
| `categories` | `tab_categories()` | `categories.*` (key/name/description) |
| `advanced` | `tab_advanced()` | `cookie_name`, `cookie_lifetime_days`, `custom_css`, reset-consents button |

## Per-tab save — CRITICAL
The form includes a hidden `<input name="lcmt_tab" value="{current-tab}">`. On save, `handleSave()` dispatches to `sanitizeForTab($tab, $input)` which returns **only the slice of settings belonging to that tab**. This partial array is then merged on top of existing settings via `Settings::save()` (which calls `array_replace_recursive`).

**Why:** without per-tab scoping, submitting the General form sends no `services` fields → the sanitizer would rebuild services from defaults → all GTM IDs wiped. Same problem in reverse for the `enabled` checkbox when saving from Services.

If you add a new setting, place it in the appropriate tab and extend exactly one branch of the `switch` in `sanitizeForTab()`.

## Reset-all-user-consents
The Advanced tab has a submit button `<button name="lcmt_action" value="reset_consents">`. `handleSave()` detects this before falling through to `sanitizeForTab`, calls `Settings::bumpConsentVersion()` which increments `consent_version` (int). The effective cookie name in `Settings::effectiveCookieName()` becomes `{cookie_name}_v{N}` when `N > 0`, so every visitor's existing consent cookie is abandoned and the banner re-shows.

## Option schema
Single autoloaded row: `lcmt_dev_consent_settings`. Defaults live in [`Settings::defaults()`](../src/Admin/Settings.php).

```php
[
    'enabled' => true,
    'position' => 'bottom-left',          // bottom-left|bottom-right|bottom-center|top-center
    'texts' => [
        'title', 'description', 'accept', 'refuse', 'personalize', 'back', 'ok',
        'service_accept', 'service_refuse', 'all_accept', 'all_refuse', 'panel_title',
    ],
    'privacy_url' => '',
    'appearance' => [
        'bg', 'text', 'hover_bg', 'hover_text', 'border',
        'radius' /* 0-50 */, 'z_index',
    ],
    'services' => [
        'googletagmanager' => ['enabled', 'id', 'category', 'display_name', 'consent_mode'],
        'googleanalytics'  => ['enabled', 'id', 'category', 'display_name'],
        'facebookpixel'    => ['enabled', 'id', 'category', 'display_name'],
        'matomo'           => ['enabled', 'url', 'site_id', 'category', 'display_name'],
    ],
    'categories' => [
        'api'      => ['name', 'description'],
        'analytic' => ['name', 'description'],
        'ads'      => ['name', 'description'],
        // plus any custom keys added from the Categories tab
    ],
    'cookie_name' => 'cookieConsent',
    'cookie_lifetime_days' => 365,
    'consent_version' => 0,
    'custom_css' => '',
]
```

## Sanitization summary
- Text fields: `sanitize_text_field` (or `sanitize_textarea_field` for description + custom_css which still goes through `wp_strip_all_tags`).
- URLs: `esc_url_raw`.
- Colors: `sanitizeColor()` — regex `/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/`, falls back to the field's default if invalid.
- Keys: `sanitize_key` (lowercase, alphanumeric + underscore).
- Integers: clamped via `max(min(…))` per field.
- Category keys created from the "Add a custom category" inline form pass through a tiny inline JS that lowercases + strips non-`[a-z0-9_]`.

## Color picker boot
Enqueue `wp-color-picker` + inline `jQuery(function($){$(".lcmt-color").wpColorPicker();});` so every `.lcmt-color` input becomes a picker. Applied via the `tab_appearance()` render.

## Admin assets constraints
Only load on the plugin's settings page (`$hook === 'settings_page_lcmt-dev-consent'`). Never run globally — adds zero overhead to other admin screens.
