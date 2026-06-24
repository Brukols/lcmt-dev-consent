<?php

namespace LcmtDev\Consent\Frontend;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Consent;

/**
 * Wraps Google Maps embeds in a consent gate. When the visitor hasn't accepted
 * the `googlemaps` service yet, the iframe is replaced by a styled placeholder
 * carrying a "Accept and display" button (handled client-side by
 * googlemaps.[hash].js).
 *
 * Unlike YouTube, Google Maps is NOT a WordPress oEmbed/block provider — maps
 * are pasted as raw `<iframe src="https://www.google.com/maps/embed?…">` (a
 * Custom HTML block in Gutenberg, or raw HTML in the Classic editor). Both end
 * up in the post body, so a single `the_content` filter (run at priority 20,
 * after `do_blocks`/`wpautop`) catches every case. We scan the rendered HTML
 * for Google-Maps iframes and swap each one, preserving the full embed URL and
 * height so the client can rebuild the exact iframe on accept.
 *
 * If the service is disabled in admin this class is a no-op and the original
 * HTML passes through unchanged. Non-Maps iframes (YouTube, etc.) are ignored.
 */
class GoogleMapsEmbed
{
    public const SERVICE_KEY = 'googlemaps';

    private const DEFAULT_HEIGHT = 450;

    public function register(): void
    {
        // Priority 20: after do_blocks (9) and wpautop (10) so we receive fully
        // rendered HTML, including the contents of Custom HTML blocks.
        add_filter('the_content', [$this, 'filterContent'], 20);
        add_filter('widget_text', [$this, 'filterContent'], 20);
    }

    /**
     * Public helper for theme/plugin code that already has a Google Maps embed
     * URL and wants the consent-gated markup.
     */
    public static function render(string $src, int $height = self::DEFAULT_HEIGHT): string
    {
        if (!self::isMapsUrl($src)) {
            return '';
        }
        if (!self::serviceEnabled() || Consent::isAllowed(self::SERVICE_KEY)) {
            return self::renderIframe($src, $height);
        }
        return self::renderPlaceholder($src, $height);
    }

    public function filterContent($content)
    {
        if (!is_string($content) || $content === '') {
            return $content;
        }
        if (!self::serviceEnabled() || Consent::isAllowed(self::SERVICE_KEY)) {
            return $content;
        }
        // Cheap early-out: skip the regex entirely when no Google host appears.
        if (stripos($content, 'google.') === false) {
            return $content;
        }

        return (string) preg_replace_callback(
            '#<iframe\b[^>]*></iframe>#i',
            function (array $m): string {
                $tag = $m[0];
                if (!preg_match('#\ssrc\s*=\s*("|\')(.*?)\1#i', $tag, $s)) {
                    return $tag;
                }
                $src = html_entity_decode($s[2], ENT_QUOTES);
                if (!self::isMapsUrl($src)) {
                    return $tag;
                }
                $height = self::DEFAULT_HEIGHT;
                if (preg_match('#\sheight\s*=\s*("|\')?(\d+)#i', $tag, $h)) {
                    $height = (int) $h[2];
                }
                return self::renderPlaceholder($src, $height);
            },
            $content
        );
    }

    /**
     * True for any Google Maps embed URL:
     *   https://www.google.com/maps/embed?pb=…
     *   https://www.google.<tld>/maps/embed?pb=…
     *   https://www.google.com/maps/d/embed?mid=…   (My Maps)
     *   https://maps.google.com/maps?…&output=embed
     */
    public static function isMapsUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);
        $isGoogleHost = $host === 'maps.google.com'
            || (bool) preg_match('#^(www\.)?google\.[a-z][a-z.]+$#', $host);
        if (!$isGoogleHost) {
            return false;
        }
        return strpos($path, '/maps') !== false;
    }

    public static function renderIframe(string $src, int $height = self::DEFAULT_HEIGHT): string
    {
        $src = esc_url($src);
        $height = $height > 0 ? $height : self::DEFAULT_HEIGHT;
        $title = esc_attr__('Google Maps map', 'lcmt-dev-consent');
        // Inline style: googlemaps.css is intentionally NOT loaded once consent
        // is granted, so the iframe carries its own sizing.
        $style = 'width:100%;height:' . $height . 'px;border:0;display:block';
        return '<iframe'
            . ' src="' . $src . '"'
            . ' title="' . $title . '"'
            . ' style="' . $style . '"'
            . ' frameborder="0"'
            . ' allowfullscreen'
            . ' loading="lazy"'
            . ' referrerpolicy="no-referrer-when-downgrade"></iframe>';
    }

    public static function renderPlaceholder(string $src, int $height = self::DEFAULT_HEIGHT): string
    {
        $srcAttr = esc_url($src);
        $height = $height > 0 ? $height : self::DEFAULT_HEIGHT;
        $title = esc_html(self::translatedString(
            'googlemaps.placeholder.title',
            'This content is hosted on Google Maps'
        ));
        $body = esc_html(self::translatedString(
            'googlemaps.placeholder.body',
            'Loading it will set cookies from Google. Accept to display the map.'
        ));
        $accept = esc_html(self::translatedString(
            'googlemaps.placeholder.accept',
            'Accept and display'
        ));

        return '<div class="lcmt-maps-placeholder"'
            . ' data-lcmt-maps-src="' . $srcAttr . '"'
            . ' data-lcmt-maps-height="' . $height . '"'
            . ' style="min-height:' . $height . 'px"'
            . ' role="region" aria-label="' . esc_attr($title) . '">'
            . '<div class="lcmt-maps-placeholder__inner">'
            . '<p class="lcmt-maps-placeholder__title">' . $title . '</p>'
            . '<p class="lcmt-maps-placeholder__body">' . $body . '</p>'
            . '<div class="lcmt-maps-placeholder__actions">'
            . '<button type="button" class="lcmt-maps-placeholder__accept">' . $accept . '</button>'
            . '</div>'
            . '</div>'
            . '</div>';
    }

    private static function serviceEnabled(): bool
    {
        $services = (array) get_option(Settings::OPTION_KEY, []);
        $services = is_array($services['services'] ?? null) ? $services['services'] : [];
        $defaults = Settings::defaults()['services'][self::SERVICE_KEY] ?? [];
        $merged = array_replace($defaults, (array) ($services[self::SERVICE_KEY] ?? []));
        return !empty($merged['enabled']);
    }

    /**
     * Run an English source string through the existing translation pipeline:
     *   1. WordPress translate() (.mo file in languages/)
     *   2. lcmt_dev_consent_string filter (lets code override)
     */
    private static function translatedString(string $name, string $default): string
    {
        $value = translate($default, 'lcmt-dev-consent');
        return (string) apply_filters('lcmt_dev_consent_string', $value, $name);
    }
}
