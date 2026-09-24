// Built-in client-side injectors. Executed the first time a service is
// accepted (same session, no reload). On subsequent page loads the
// server-side ScriptInjector handles injection directly in <head>.
//
// For custom services registered via the PHP filter, either:
//   - set `needReload: true` so the page reloads after acceptance and the
//     server-side injector runs, OR
//   - listen for the `lcmt-consent:accepted` CustomEvent on `document` to
//     run your own client-side injection when the user just accepted.

type InjectorFn = (data: Record<string, string>) => void;

function inlineScript(code: string): void {
    const s = document.createElement("script");
    s.textContent = code;
    document.head.appendChild(s);
}

function externalScript(src: string, async = true): void {
    const s = document.createElement("script");
    s.async = async;
    s.src = src;
    document.head.appendChild(s);
}

declare global {
    interface Window {
        dataLayer?: unknown[];
        gtag?: (...args: unknown[]) => void;
    }
}

let pendingSignals: Record<string, "granted"> | null = null;

// Signals accepted in the same commit are sent as a single consent update, as
// Google recommends: one update per signal made gtag send hits with a partial
// consent state in between. The flush runs as a microtask, after the banner's
// synchronous commit loop and before loadSiteKitTag's setTimeout.
function gtagConsentUpdate(signal: string): void {
    if (pendingSignals) {
        pendingSignals[signal] = "granted";
        return;
    }
    pendingSignals = { [signal]: "granted" };
    queueMicrotask(() => {
        const signals = pendingSignals;
        pendingSignals = null;
        // The server-side Consent Mode snippet defined window.gtag in <head>.
        // Fallback-create it if the snippet didn't run for any reason.
        if (typeof window.gtag !== "function") {
            window.dataLayer = window.dataLayer || [];
            window.gtag = function (...args: unknown[]) {
                (window.dataLayer as unknown[]).push(args);
            };
        }
        window.gtag("consent", "update", signals);
    });
}

let googleTagRequested = false;

// Google Site Kit, basic mode: its tag is blocked server-side until consent, so
// the first accept loads it here (later pages get Site Kit's own tag back).
// Deferred to the next task so every signal accepted in the same commit is
// already updated when the tag's config runs.
function loadSiteKitTag(id: string): void {
    if (googleTagRequested) return;
    googleTagRequested = true;
    setTimeout(() => {
        externalScript(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`);
        window.gtag?.("js", new Date());
        window.gtag?.("config", id);
    }, 0);
}

function consentSignal(signal: string): InjectorFn {
    return ({ sitekit_id }) => {
        gtagConsentUpdate(signal);
        if (sitekit_id) loadSiteKitTag(sitekit_id);
    };
}

const INJECTORS: Record<string, InjectorFn> = {
    google_analytics_storage: consentSignal("analytics_storage"),
    google_ad_storage: consentSignal("ad_storage"),
    google_ad_user_data: consentSignal("ad_user_data"),
    google_ad_personalization: consentSignal("ad_personalization"),
    googletagmanager: ({ id }) => {
        if (!id) return;
        inlineScript(
            `(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer',${JSON.stringify(id)});`
        );
    },
    googleanalytics: ({ id }) => {
        if (!id) return;
        externalScript(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`);
        inlineScript(
            `window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',${JSON.stringify(id)});`
        );
    },
    facebookpixel: ({ id }) => {
        if (!id) return;
        inlineScript(
            `!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init',${JSON.stringify(id)});fbq('track','PageView');`
        );
    },
    matomo: ({ url, site_id }) => {
        if (!url || !site_id) return;
        const u = url.endsWith("/") ? url : url + "/";
        inlineScript(
            `var _paq=window._paq=window._paq||[];_paq.push(['trackPageView']);_paq.push(['enableLinkTracking']);(function(){var u=${JSON.stringify(u)};_paq.push(['setTrackerUrl',u+'matomo.php']);_paq.push(['setSiteId',${JSON.stringify(site_id)}]);var d=document,g=d.createElement('script'),s=d.getElementsByTagName('script')[0];g.async=true;g.src=u+'matomo.js';s.parentNode.insertBefore(g,s);})();`
        );
    }
};

export function runInjector(key: string, data: Record<string, string>) {
    INJECTORS[key]?.(data);
    document.dispatchEvent(
        new CustomEvent("lcmt-consent:accepted", { detail: { key, data } })
    );
}
