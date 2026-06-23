# Design: Consent Log ("Registre / preuve de consentement")

**Date:** 2026-06-23
**Plugin:** `lcmt-dev-consent`
**Status:** Approved design — ready for implementation plan

## Background & legal basis

RGPD **Article 7(1)** requires the data controller to be able to **demonstrate
that consent was given** ("être en mesure de démontrer que la personne a donné
son consentement"). The CNIL *recommandation cookies* (2020) reinforces this for
cookie consent: the organization must be able to **provide proof of consent
collection at any time**.

Note the terminology trap: this is **not** the *registre des traitements*
(RGPD Art. 30, a record of processing activities) — it is a **preuve / journal de
consentement** (an auditable, controller-side log of consent events).

**Current gap:** consent lives **only in a client-side cookie**
([src/Consent.php](../../../src/Consent.php)). The user controls that cookie, it
is not auditable by the controller, and it disappears when cleared — so today the
site has **no proof of consent**. This feature closes that gap with a
server-side, append-only consent log.

## Goals

- Persist an auditable record of every affirmative consent action.
- Keep the proof **data-minimized**: anonymous consent ID only, no IP / identity.
- Give the controller an in-admin way to browse, search, and export the log
  (CSV) to answer audits / regulator requests.
- Auto-purge old records (storage limitation), configurable.
- **Do not break existing users** who already hold a consent cookie.

## Non-goals

- No registre des traitements (Art. 30) — out of scope.
- No storing of IP, user-agent, or personal identity.
- No retroactive proof for consents given before this feature ships.

## Decisions (from brainstorming)

| Topic | Decision |
|-------|----------|
| Events logged | `accept_all`, `reject_all`, `custom` (granular save), `withdraw` (later change/withdrawal) |
| Identifier | **Anonymous consent ID** (random UUID) — no IP, no identity |
| Storage | **Custom DB table** `wp_lcmt_consent_log`, created on activation |
| Admin access | New **"Registre de consentement"** tab: paginated/searchable table + CSV export |
| Retention | **Configurable, default 36 months**; daily WP-Cron purge |
| Cookie | `consent_id` **embedded in the existing consent cookie** as `cid=<uuid>` |

## What each record stores

To support proof that consent was **free, specific, informed, unambiguous**, one
row per event in `wp_lcmt_consent_log`:

| Column | Type | Purpose |
|--------|------|---------|
| `id` | BIGINT UNSIGNED, PK, AUTO_INCREMENT | Primary key |
| `consent_id` | CHAR(36), indexed | Anonymous random UUID, also stored in cookie; correlates a later withdrawal to the original consent |
| `event` | VARCHAR(20), indexed | `accept_all` / `reject_all` / `custom` / `withdraw` |
| `choices` | TEXT (JSON) | Per-service/category state at the moment, e.g. `{"analytics":true,"ads":false}` — proves *specific* |
| `policy_version` | VARCHAR(40), indexed | Short hash of the services list + consent texts shown at that time — proves *informed*; lets the controller reconstruct what the banner offered |
| `cookie_version` | VARCHAR(40) | The `cookieConsent_v{N}` generation (already bumped on "Reset all user consents") |
| `created_at` | DATETIME (UTC), indexed | When the event happened — proves *when* |

Deliberately **no IP, no user-agent, no identity**.

## Architecture

### Event flow (browser → server)

1. Banner TS generates (or reads from the cookie) the `consent_id`.
2. On accept / reject / save / withdraw, the TS:
   - writes the existing `key=value!` service pairs to the cookie **plus** `cid=<uuid>`;
   - `fetch()`es `POST /wp-json/lcmt-dev-consent/v1/log` with **only** `{ event }`
     and a WP REST nonce.
3. Server (`RestController`) validates the `event` + nonce, then derives every
   other field server-side for integrity — it never trusts the request body for
   them: `consent_id` and `choices` are read from the consent **cookie** sent with
   the request, `policy_version` is re-computed from current settings, and
   `cookie_version` from `consent_version`. It then inserts a row via
   `ConsentLog::insert()`. (Only `event` cannot be inferred from the cookie, so it
   is the sole body field.)

Logging is **best-effort**: the user's consent choice is applied client-side
regardless of whether the request succeeds (see Error handling).

### New PHP components

- `src/Log/ConsentLog.php`
  - `createTable()` — `dbDelta` on activation, guarded by a stored schema version.
  - `insert(array $record): int`
  - `query(array $filters, int $page, int $perPage): array` — for the admin table.
  - `exportCsv(array $filters): string` (streamed) — full log or date range.
  - `purgeOlderThan(int $months): int` — called by cron.
  - `policyVersion(): string` — hash of current services + consent texts.
- `src/Log/RestController.php`
  - Registers `POST /lcmt-dev-consent/v1/log` (public route, nonce-protected,
    rate-limited), validates, calls `ConsentLog::insert()`.

### Modified components

- `src/Consent.php` — unchanged parsing already tolerates a `cid` key; add a
  helper `getConsentId(string $cookieName): ?string` for completeness.
- `src/Admin/SettingsPage.php` — new **"Registre de consentement"** tab:
  paginated, searchable table (by `consent_id`, `event`, date range), a CSV
  export button, and a retention-period (months) setting field.
- `src/Plugin.php` — wire `RestController`, register activation hook for
  `ConsentLog::createTable()`, register the daily purge cron event.
- `assets/src/banner.ts` / `assets/src/injectors.ts` — generate/read `cid`,
  embed it in the cookie, call the REST endpoint on each event.
  **Requires rebuilding the JS bundle** (`webpack`) → new hashed `dist/` files +
  updated `manifest.json`.
- `uninstall.php` — drop the table + clear the cron event on uninstall.

### Settings additions

Stored in the existing single option row `lcmt_dev_consent_settings`:

- `log_enabled` (bool, default true)
- `log_retention_months` (int, default 36)

## Backward compatibility (existing consent cookies)

Existing users hold cookies like `analytics=true!ads=false` with **no `cid`**.

- **Parsing safe:** `Consent::getCookies()` parses `cid=…` as just another key;
  `isComplete()` checks only service keys, so an extra `cid` is ignored and a
  *missing* `cid` changes nothing. Existing valid consents keep working; the
  banner stays hidden for them.
- **No retroactive proof:** no log row is fabricated for pre-feature consents.
- **Lazy `cid` minting:** if a returning user with no `cid` opens preferences and
  changes/withdraws, the TS mints a fresh `consent_id` at that moment, embeds it,
  and logs the event — so legacy users' future changes/withdrawals are captured.

## Error handling & edge cases

- **Never block consent:** REST failure does not prevent the cookie from being
  set client-side. Logging uses one short retry, then fails silently (optionally
  noted in a debug log when `WP_DEBUG`).
- **Anti-flood:** REST route requires a valid nonce + a lightweight per-IP rate
  limit using a transient counter (the counter is **not** written to the log;
  no IP is persisted).
- **Schema migration:** `createTable()` is guarded by a stored schema version so
  plugin upgrades migrate cleanly via `dbDelta`.
- **Cron resilience:** purge event is (re)scheduled on boot if missing.
- **CSV size:** export is streamed / chunked to avoid memory spikes on large logs.

## Testing

- **PHP unit tests:** `ConsentLog` insert / query / purge / CSV; `policyVersion()`
  determinism; `RestController` validation (bad payload, missing nonce, valid
  insert, rate-limit).
- **Backward-compat test:** a cookie without `cid` still parses and
  `isComplete()` behaves identically.
- **Manual end-to-end:** accept → row appears; withdraw → second row with the
  **same** `consent_id`; CSV export downloads and opens; cron purge removes rows
  older than the retention window.

## Open questions

None blocking. (Record columns and single-cookie embedding confirmed during
brainstorming.)
