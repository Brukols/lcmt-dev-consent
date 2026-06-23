import "./youtube.scss";
// Importing the .ts side-effect-free brings in its `declare global` for
// `window.lcmtConsent` so we don't redeclare it (would conflict). Webpack
// tree-shakes the runtime imports — the file declares only types + globals.
import type {} from "./types";
import { ensureConsentId, logConsentEvent } from "./consent-log";

/**
 * Standalone YouTube placeholder upgrader.
 *
 * Loaded by Frontend/Assets::enqueueYoutube() only when the YouTube service is
 * enabled and the visitor hasn't accepted it yet. Two paths swap a placeholder
 * for the real iframe:
 *
 *   1. The visitor clicks the placeholder's "Accept and play" button. We write
 *      `youtube=true` into the consent cookie ourselves (the banner script may
 *      not be loaded — e.g. all other services were already decided), then
 *      dispatch `lcmt-consent:accepted` so any other placeholders + listeners
 *      react.
 *   2. The visitor accepts YouTube via the main banner. The banner's commit()
 *      already dispatches `lcmt-consent:accepted` for newly-accepted services,
 *      so we just listen for it.
 *
 * The iframe markup must stay in sync with PHP `YouTubeEmbed::renderIframe()`.
 * Five attributes today; if either side changes, change both.
 */

const DEFAULT_COOKIE_NAME = "cookieConsent";
const DEFAULT_LIFETIME_DAYS = 365;
const SERVICE_KEY = "youtube";

function readCookie(name: string): string | null {
    const target = name + "=";
    for (const part of document.cookie.split("; ")) {
        if (part.indexOf(target) === 0) {
            return decodeURIComponent(part.substring(target.length));
        }
    }
    return null;
}

function writeCookie(name: string, value: string, days: number): void {
    const expires = new Date(Date.now() + days * 864e5).toUTCString();
    document.cookie = `${name}=${encodeURIComponent(value)}; expires=${expires}; path=/; SameSite=Lax`;
}

type CookieEntry = { key: string; status: string };

function parseConsentCookie(raw: string | null): CookieEntry[] {
    if (!raw) return [];
    return raw
        .split("!")
        .filter((s) => s.length > 0)
        .map((s) => {
            const [key, status] = s.split("=");
            return { key, status };
        });
}

function serializeConsentCookie(entries: CookieEntry[]): string {
    return entries.map((e) => `!${e.key}=${e.status}`).join("");
}

function setYoutubeAccepted(): void {
    // Localized config is attached to whichever script handle ran wp_localize_script;
    // when this bundle is enqueued, Assets::enqueueYoutube() always localizes it here.
    // Defensive defaults in case a hosting setup strips inline scripts.
    const cfg = window.lcmtConsent;
    const cookieName = cfg?.cookieName || DEFAULT_COOKIE_NAME;
    const lifetime = cfg?.cookieLifetimeDays || DEFAULT_LIFETIME_DAYS;

    const entries = parseConsentCookie(readCookie(cookieName));
    const existing = entries.find((e) => e.key === SERVICE_KEY);
    if (existing) {
        existing.status = "true";
    } else {
        entries.push({ key: SERVICE_KEY, status: "true" });
    }

    const cidEntry = entries.find((e) => e.key === "cid");
    const cid = ensureConsentId(cidEntry ? cidEntry.status : null);
    if (cidEntry) {
        cidEntry.status = cid;
    } else {
        entries.push({ key: "cid", status: cid });
    }

    writeCookie(cookieName, serializeConsentCookie(entries), lifetime);
    logConsentEvent("custom");
}

function buildIframe(videoId: string): HTMLIFrameElement {
    const iframe = document.createElement("iframe");
    iframe.src = `https://www.youtube-nocookie.com/embed/${encodeURIComponent(videoId)}?autoplay=1`;
    iframe.title = "YouTube video player";
    // Inline sizing — matches PHP YouTubeEmbed::renderIframe() so the iframe
    // looks identical whether the user just accepted (this swap path) or
    // landed on a page with consent already granted (server-side render path).
    iframe.style.cssText = "width:100%;aspect-ratio:16/9;height:auto;border:0;display:block";
    iframe.setAttribute("frameborder", "0");
    iframe.setAttribute(
        "allow",
        "accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
    );
    iframe.setAttribute("allowfullscreen", "");
    iframe.loading = "lazy";
    return iframe;
}

function swapPlaceholder(placeholder: HTMLElement): void {
    const videoId = placeholder.getAttribute("data-lcmt-youtube-id") || "";
    if (!videoId) return;
    const iframe = buildIframe(videoId);
    placeholder.replaceWith(iframe);
}

function swapAllPlaceholders(): void {
    document.querySelectorAll<HTMLElement>(".lcmt-yt-placeholder[data-lcmt-youtube-id]").forEach(swapPlaceholder);
}

function handleAcceptClick(placeholder: HTMLElement): void {
    setYoutubeAccepted();
    document.dispatchEvent(
        new CustomEvent("lcmt-consent:accepted", { detail: { key: SERVICE_KEY, data: {} } })
    );
    // Defensive: the event listener swaps everything, but if for any reason
    // it didn't fire (e.g. external listener stops propagation), swap this one.
    if (placeholder.isConnected) {
        swapPlaceholder(placeholder);
    }
}

function bindPlaceholder(placeholder: HTMLElement): void {
    const acceptBtn = placeholder.querySelector<HTMLButtonElement>(".lcmt-yt-placeholder__accept");
    if (acceptBtn) {
        acceptBtn.addEventListener("click", () => handleAcceptClick(placeholder));
    }
}

function boot(): void {
    document
        .querySelectorAll<HTMLElement>(".lcmt-yt-placeholder[data-lcmt-youtube-id]")
        .forEach(bindPlaceholder);

    document.addEventListener("lcmt-consent:accepted", (event: Event) => {
        const detail = (event as CustomEvent).detail as { key?: string } | undefined;
        if (detail?.key === SERVICE_KEY) {
            swapAllPlaceholders();
        }
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
} else {
    boot();
}
