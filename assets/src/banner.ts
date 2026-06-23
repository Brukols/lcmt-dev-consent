import "./banner.scss";
import { runInjector } from "./injectors";
import type { ServiceConfig, Status } from "./types";

interface CookieValue {
    key: string;
    status: Status;
}

function getCookie(name: string): string | null {
    const target = name + "=";
    const parts = document.cookie.split("; ");
    for (const p of parts) {
        if (p.indexOf(target) === 0) {
            return decodeURIComponent(p.substring(target.length));
        }
    }
    return null;
}

function setCookie(name: string, value: string, days: number): void {
    const expires = new Date(Date.now() + days * 864e5).toUTCString();
    document.cookie = `${name}=${encodeURIComponent(value)}; expires=${expires}; path=/; SameSite=Lax`;
}

class ConsentBanner {
    private root: HTMLElement;
    private mainView: HTMLElement;
    private panel: HTMLElement;
    private services: ServiceConfig[];
    private cookieName: string;
    private cookieLifetimeDays: number;
    private values: CookieValue[] = [];

    constructor(root: HTMLElement) {
        this.root = root;
        this.mainView = root.querySelector("#lcmt-consent-main") as HTMLElement;
        this.panel = root.querySelector("#lcmt-consent-panel") as HTMLElement;

        const config = window.lcmtConsent;
        this.services = config?.services ?? [];
        this.cookieName = config?.cookieName ?? "cookieConsent";
        this.cookieLifetimeDays = config?.cookieLifetimeDays ?? 365;

        if (!this.mainView || !this.panel || this.services.length === 0) return;

        this.initValues();
        this.initPanel();
        this.bindEvents();
    }

    private initValues(): void {
        this.values = this.parseCookie();
        let shouldOpen = false;

        this.services.forEach((svc) => {
            const existing = this.values.find((v) => v.key === svc.key);
            if (!existing) {
                this.values.push({ key: svc.key, status: "wait" });
                shouldOpen = true;
            } else if (existing.status === "wait") {
                shouldOpen = true;
            }
        });

        // Prune stale keys that no longer correspond to a configured service
        this.values = this.values.filter((v) => this.services.some((s) => s.key === v.key));

        if (shouldOpen) {
            this.root.setAttribute("data-opened", "true");
        }
        this.saveCookie();
    }

    private parseCookie(): CookieValue[] {
        const raw = getCookie(this.cookieName);
        if (!raw) return [];
        return raw
            .split("!")
            .filter((s) => s.length > 0)
            .map((s) => {
                const [key, status] = s.split("=");
                return { key, status: status as Status };
            });
    }

    private saveCookie(): void {
        const value = this.values.map((v) => `!${v.key}=${v.status}`).join("");
        setCookie(this.cookieName, value, this.cookieLifetimeDays);
    }

    private initPanel(): void {
        // Sync service buttons to current state
        this.values.forEach((v) => {
            this.updateServiceButtons(v.key, v.status);
        });
    }

    private updateServiceButtons(key: string, status: Status): void {
        const accept = this.panel.querySelector<HTMLButtonElement>(
            `.lcmt-consent__svc-accept[data-key="${key}"]`
        );
        const refuse = this.panel.querySelector<HTMLButtonElement>(
            `.lcmt-consent__svc-refuse[data-key="${key}"]`
        );
        if (accept) accept.setAttribute("aria-selected", status === "true" ? "true" : "false");
        if (refuse) refuse.setAttribute("aria-selected", status === "false" ? "true" : "false");
    }

    private bindEvents(): void {
        this.mainView
            .querySelector(".lcmt-consent__personalize")
            ?.addEventListener("click", () => this.showPanel());
        this.panel
            .querySelector(".lcmt-consent__back")
            ?.addEventListener("click", () => this.showMain());

        this.root.querySelectorAll<HTMLButtonElement>(".lcmt-consent__accept-all").forEach((btn) => {
            btn.addEventListener("click", () => this.acceptAll());
        });
        this.root.querySelectorAll<HTMLButtonElement>(".lcmt-consent__refuse-all").forEach((btn) => {
            btn.addEventListener("click", () => this.refuseAll());
        });

        this.panel.querySelectorAll<HTMLButtonElement>(".lcmt-consent__svc-accept").forEach((b) => {
            b.addEventListener("click", () => this.selectService(b.dataset.key!, "true"));
        });
        this.panel.querySelectorAll<HTMLButtonElement>(".lcmt-consent__svc-refuse").forEach((b) => {
            b.addEventListener("click", () => this.selectService(b.dataset.key!, "false"));
        });

        this.panel
            .querySelector(".lcmt-consent__ok")
            ?.addEventListener("click", () => this.commit());
    }

    private showPanel(): void {
        this.mainView.setAttribute("data-opened", "false");
        this.panel.setAttribute("data-opened", "true");
    }

    private showMain(): void {
        this.mainView.setAttribute("data-opened", "true");
        this.panel.setAttribute("data-opened", "false");
    }

    private isPanelOpen(): boolean {
        return this.panel.getAttribute("data-opened") === "true";
    }

    private selectService(key: string, status: Status): void {
        this.values.forEach((v) => {
            if (v.key === key) v.status = status;
        });
        this.updateServiceButtons(key, status);
    }

    private acceptAll(): void {
        this.values.forEach((v) => {
            v.status = "true";
            this.updateServiceButtons(v.key, "true");
        });
        if (!this.isPanelOpen()) this.commit();
    }

    private refuseAll(): void {
        this.values.forEach((v) => {
            v.status = "false";
            this.updateServiceButtons(v.key, "false");
        });
        if (!this.isPanelOpen()) this.commit();
    }

    private commit(): void {
        let needReload = false;
        const previouslyAccepted: Record<string, boolean> = {};
        this.parseCookie().forEach((v) => {
            previouslyAccepted[v.key] = v.status === "true";
        });

        this.values.forEach((v) => {
            if (v.status === "wait") v.status = "false";
            const svc = this.services.find((s) => s.key === v.key);
            if (!svc) return;
            if (v.status === "true" && !previouslyAccepted[v.key]) {
                if (svc.needReload) {
                    needReload = true;
                } else {
                    runInjector(svc.key, svc.data);
                }
            }
        });

        this.saveCookie();
        this.hide();
        if (needReload) window.location.reload();
    }

    private hide(): void {
        this.root.setAttribute("data-opened", "false");
    }
}

function boot() {
    const root = document.getElementById("lcmt-consent");
    if (root) new ConsentBanner(root);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
} else {
    boot();
}
