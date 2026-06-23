# Frontend — banner, build, styling

## Files
- `assets/src/banner.ts` — `ConsentBanner` class, cookie read/write, event wiring
- `assets/src/injectors.ts` — Built-in per-service injectors + `runInjector()` helper
- `assets/src/types.ts` — Shared TS types, `window.lcmtConsent` declaration
- `assets/src/banner.scss` — Styles, BEM class names, CSS variables on `:root`
- `assets/src/youtube.ts` — Standalone YouTube placeholder upgrader (separate webpack entry)
- `assets/src/youtube.scss` — Placeholder styles, reuses `--lcmt-consent-*` variables
- `assets/dist/` — Built + committed output
- `webpack.config.js` — esbuild-loader (fast TS) + sass-loader + MiniCssExtract + WebpackManifestPlugin; two entries: `banner` and `youtube`
- `tsconfig.json` — `strict: true`, `noEmit: true` (type-check only; build does the emit)
- `package.json` scripts: `build` (prod, hashed), `build:dev`, `watch`

## Run commands
```bash
npm install
npm run build        # production, hashed filenames
npm run build:dev    # unhashed, sourcemaps
npm run watch        # dev watch mode
```

`npm run build` produces `assets/dist/banner.[contenthash:8].{js,css}`, `youtube.[contenthash:8].{js,css}`, and `manifest.json` (used by [`Assets::readManifest()`](../src/Frontend/Assets.php) to resolve hashed filenames at runtime).

## Runtime behavior (banner.ts)

On first-time visit (some service at `wait`):
1. `ConsentBanner` instance parses the cookie, fills in `wait` for missing services, prunes stale keys, opens the banner.
2. User clicks:
   - **Accept all / Refuse all** on main view → sets every value → `commit()` if panel is closed.
   - **Personalize** → swap views (`data-opened` attribute toggle on `#lcmt-consent-main` / `#lcmt-consent-panel`).
   - **Per-service Accept/Refuse** → updates `aria-selected` on matching buttons only.
   - **OK** on panel → `commit()`.
3. `commit()`:
   - Anything still `wait` becomes `false`.
   - For each service newly flipped to `true`: call `runInjector(svc.key, svc.data)` unless `svc.needReload` is set (in which case set `needReload = true`).
   - Save cookie, close banner, reload if any `needReload`.

## Cookie format (compatible with the original theme implementation)
```
!key1=status!key2=status!…
status ∈ { "wait", "true", "false" }
```
Written by `setCookie()` in `banner.ts` with `SameSite=Lax; path=/; expires=<days from now>`.

## CSS architecture

### Variables live on `:root` (in SCSS)
```scss
:root {
    --lcmt-consent-bg: #ffffff;
    --lcmt-consent-text: #000000;
    --lcmt-consent-hover-bg: #ffeb3b;
    --lcmt-consent-hover-text: #000000;
    --lcmt-consent-border: #000000;
    --lcmt-consent-radius: 0px;
    --lcmt-consent-z: 1001;
}
```
**Do not** move these under `.lcmt-consent` — the PHP-emitted `<style>:root{--…}</style>` block from `Banner::renderStyleVars()` is also at `:root` specificity, so theme overrides work cleanly. A class-scoped declaration out-specifies `:root` and will block admin color changes.

### Primary button is "reversed"
`.lcmt-consent__btn--primary` uses `--lcmt-consent-hover-*` as its default state (standing out as if already focused), and `--lcmt-consent-bg` / `--lcmt-consent-text` as its hover. This lets one color pair drive both "regular buttons + accent" without a dedicated primary-color field.

### `!important` — only where needed
Host themes commonly ship `button { background-color: transparent }`-style resets at equal specificity that load after plugin CSS. `!important` is applied to the primary button's `background` / `color` + all `:hover` states + the `[aria-selected="true"]` per-service buttons. Not applied broadly — only on declarations known to be attacked.

### Overflow + scroll
The wrapper uses `display: flex; flex-direction: column; max-height: calc(100vh - 40px)`. `.lcmt-consent__view[data-opened="true"]` is `display: flex` (column). `.lcmt-consent__body` has `overflow-y: auto; min-height: 0; flex: 1 1 auto` so the list scrolls while `.lcmt-consent__actions` stays pinned at the bottom (`flex-shrink: 0`). Applies on all viewports — not inside a media query.

## YouTube bundle (`youtube.ts` + `youtube.scss`)
A separate webpack entry, enqueued by `Assets::enqueueYoutube()` only when:
1. The plugin is enabled, and
2. The `youtube` service is enabled in admin, and
3. The visitor hasn't already accepted YouTube.

A returning visitor who accepted gets **zero bytes** of this bundle. The script is intentionally standalone — it does NOT depend on `banner.js` being loaded (that script is dismissed once consent is complete for the rest of the services). It localizes its own `window.lcmtConsent` slice via `wp_localize_script` on the `lcmt-dev-consent-youtube` handle, with the same shape as the banner — so if both bundles end up loaded on the same page, the second `wp_localize_script` overwrites identically (idempotent).

The script:
- Finds every `[data-lcmt-youtube-id]` placeholder on DOM ready.
- Wires "Accept and play" → write `youtube=true` into the consent cookie ourselves (own minimal cookie utilities) → dispatch `lcmt-consent:accepted` → swap placeholder for iframe.
- Listens for `lcmt-consent:accepted` (`detail.key === 'youtube'`) so a banner-driven accept upgrades every placeholder on the page.

The iframe markup is **duplicated** between PHP `YouTubeEmbed::renderIframe()` and JS `buildIframe()` — five attributes today (`src`, `title`, `class`, `frameborder`, `allow`, `allowfullscreen`, `loading`). If you change one side, change the other.

## Custom events
After a user accepts a service, the banner dispatches:
```js
document.dispatchEvent(new CustomEvent('lcmt-consent:accepted', {
    detail: { key, data }
}));
```
Themes/plugins can hook into this to run custom logic when a filter-registered service is accepted (see [extending.md](extending.md)).

## Build caveats
- `sass-loader` emits a legacy-API deprecation warning; benign, will need a future migration.
- `assets/src/` must NOT be shipped to prod — excluded in the deploy rsync step.
- If `manifest.json` is missing from `dist/`, `Assets::enqueue()` silently skips — so a failed build fails soft (banner just won't render).
