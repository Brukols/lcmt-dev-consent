import "./googlemaps.scss";
// Importing the types side-effect-free brings in the `declare global` for
// `window.lcmtConsent` so we don't redeclare it (would conflict).
import type {} from "./types";
import { ensureConsentId, logConsentEvent } from "./consent-log";

/**
 * Standalone Google Maps placeholder upgrader.
 *
 * Loaded by Frontend/Assets::enqueueGooglemaps() only when the Google Maps
 * service is enabled and the visitor hasn't accepted it yet. Two paths swap a
 * placeholder for the real iframe:
 *
 *   1. The visitor clicks the placeholder's "Accept and display" button. We
 *      write `googlemaps=true` into the consent cookie ourselves (the banner
 *      script may not be loaded), then dispatch `lcmt-consent:accepted` so any
 *      other placeholders + listeners react.
 *   2. The visitor accepts Google Maps via the main banner. The banner's
 *      commit() already dispatches `lcmt-consent:accepted` for newly-accepted
 *      services, so we just listen for it.
 *
 * The iframe markup mirrors PHP `GoogleMapsEmbed::renderIframe()`.
 */

const DEFAULT_COOKIE_NAME = "cookieConsent";
const DEFAULT_LIFETIME_DAYS = 365;
const DEFAULT_HEIGHT = 450;
const SERVICE_KEY = "googlemaps";

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

function setGooglemapsAccepted(): void {
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

function buildIframe(src: string, height: number): HTMLIFrameElement {
    const iframe = document.createElement("iframe");
    iframe.src = src;
    iframe.title = "Google Maps map";
    // Inline sizing — matches PHP GoogleMapsEmbed::renderIframe().
    iframe.style.cssText = `width:100%;height:${height}px;border:0;display:block`;
    iframe.setAttribute("frameborder", "0");
    iframe.setAttribute("allowfullscreen", "");
    iframe.loading = "lazy";
    iframe.referrerPolicy = "no-referrer-when-downgrade";
    return iframe;
}

function swapPlaceholder(placeholder: HTMLElement): void {
    const src = placeholder.getAttribute("data-lcmt-maps-src") || "";
    if (!src) return;
    const height = parseInt(placeholder.getAttribute("data-lcmt-maps-height") || "", 10) || DEFAULT_HEIGHT;
    const iframe = buildIframe(src, height);
    placeholder.replaceWith(iframe);
}

function swapAllPlaceholders(): void {
    document
        .querySelectorAll<HTMLElement>(".lcmt-maps-placeholder[data-lcmt-maps-src]")
        .forEach(swapPlaceholder);
}

function handleAcceptClick(placeholder: HTMLElement): void {
    setGooglemapsAccepted();
    document.dispatchEvent(
        new CustomEvent("lcmt-consent:accepted", { detail: { key: SERVICE_KEY, data: {} } })
    );
    if (placeholder.isConnected) {
        swapPlaceholder(placeholder);
    }
}

function bindPlaceholder(placeholder: HTMLElement): void {
    const acceptBtn = placeholder.querySelector<HTMLButtonElement>(".lcmt-maps-placeholder__accept");
    if (acceptBtn) {
        acceptBtn.addEventListener("click", () => handleAcceptClick(placeholder));
    }
}

function boot(): void {
    document
        .querySelectorAll<HTMLElement>(".lcmt-maps-placeholder[data-lcmt-maps-src]")
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
