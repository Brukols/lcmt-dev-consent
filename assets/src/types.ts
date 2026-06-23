export type Status = "wait" | "true" | "false";

export interface ServiceConfig {
    key: string;
    name: string;
    category: string;
    needReload: boolean;
    data: Record<string, string>;
}

export interface CategoryConfig {
    name: string;
    description: string;
}

export interface Texts {
    title: string;
    description: string;
    accept: string;
    refuse: string;
    personalize: string;
    back: string;
    ok: string;
    service_accept: string;
    service_refuse: string;
    all_accept: string;
    all_refuse: string;
    panel_title: string;
}

export interface LogConfig {
    enabled: boolean;
    endpoint: string;
    nonce: string;
}

export interface LcmtConsentConfig {
    cookieName: string;
    cookieLifetimeDays: number;
    services: ServiceConfig[];
    categories: Record<string, CategoryConfig>;
    texts: Texts;
    log?: LogConfig;
}

declare global {
    interface Window {
        lcmtConsent?: LcmtConsentConfig;
    }
}
