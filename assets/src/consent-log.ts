import type {} from "./types";

export function generateConsentId(): string {
    const c = (typeof crypto !== "undefined" ? crypto : undefined) as Crypto | undefined;
    if (c && typeof c.randomUUID === "function") {
        return c.randomUUID();
    }
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (ch) => {
        const r = (Math.random() * 16) | 0;
        const v = ch === "x" ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

export function ensureConsentId(existing: string | null): string {
    return existing && existing.length > 0 ? existing : generateConsentId();
}

// Best-effort: never throws, never blocks the consent decision.
export function logConsentEvent(event: string): void {
    const log = window.lcmtConsent?.log;
    if (!log || !log.enabled || !log.endpoint) {
        return;
    }
    try {
        void fetch(log.endpoint, {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-WP-Nonce": log.nonce },
            credentials: "same-origin",
            keepalive: true,
            body: JSON.stringify({ event }),
        }).catch(() => {});
    } catch (_e) {
        /* swallow */
    }
}
