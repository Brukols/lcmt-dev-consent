<?php

namespace LcmtDev\Consent\Frontend;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Consent;

/**
 * Wraps YouTube embeds in a consent gate. When the visitor hasn't accepted the
 * `youtube` service yet, the iframe is replaced by a styled placeholder that
 * carries an "Accept and play" button (handled client-side by youtube.[hash].js).
 *
 * Three integration points share the same helpers:
 *  - `wp_oembed_get_html` filter — for any youtube.com / youtu.be URL pasted
 *    in content (Gutenberg paragraph auto-discovery, Classic editor oEmbed).
 *  - `render_block` filter — for `core/embed` and the legacy
 *    `core-embed/youtube` Gutenberg blocks.
 *  - `YouTubeEmbed::render()` — for explicit theme calls (e.g. the product page
 *    template), so theme code never has to know about the regex or the cookie
 *    state.
 *
 * If the YouTube service is disabled in admin, this class becomes a no-op and
 * the original embed/HTML passes through unchanged. If the URL doesn't parse
 * as a recognizable YouTube video ID, the same passthrough applies — we'd
 * rather render the original (possibly broken) embed than a misleading
 * placeholder.
 */
class YouTubeEmbed
{
    public const SERVICE_KEY = 'youtube';

    public function register(): void
    {
        // `embed_oembed_html` covers Classic Editor auto-embeds and the [embed] shortcode
        // (the most common path). `wp_oembed_get_html` covers explicit calls to
        // wp_oembed_get_html() — both share the same 4-arg signature and the same handler.
        add_filter('embed_oembed_html', [$this, 'filterOembedHtml'], 10, 4);
        add_filter('wp_oembed_get_html', [$this, 'filterOembedHtml'], 10, 4);
        add_filter('render_block', [$this, 'filterRenderBlock'], 10, 2);
    }

    /**
     * Public helper for theme/plugin code that already has a YouTube URL or ID
     * and wants the consent-gated markup. Returns either the placeholder or
     * the real iframe, depending on consent state.
     */
    public static function render(string $videoIdOrUrl): string
    {
        $videoId = self::extractVideoId($videoIdOrUrl);
        if ($videoId === null) {
            return '';
        }
        if (!self::serviceEnabled() || Consent::isAllowed(self::SERVICE_KEY)) {
            return self::renderIframe($videoId);
        }
        return self::renderPlaceholder($videoId);
    }

    /** @param string $url Original URL passed to wp_oembed_get_html. */
    public function filterOembedHtml($html, $url, $attr, $postId)
    {
        if (!self::serviceEnabled()) {
            return $html;
        }
        if (!is_string($url) || !$this->isYoutubeUrl($url)) {
            return $html;
        }
        if (Consent::isAllowed(self::SERVICE_KEY)) {
            return $html; // user accepted — let the original (possibly themed) iframe through
        }
        $videoId = self::extractVideoId($url);
        if ($videoId === null) {
            return $html;
        }
        return self::renderPlaceholder($videoId);
    }

    public function filterRenderBlock(string $blockContent, array $block): string
    {
        if (!self::serviceEnabled()) {
            return $blockContent;
        }
        $name = $block['blockName'] ?? '';
        if ($name !== 'core/embed' && $name !== 'core-embed/youtube') {
            return $blockContent;
        }
        // For core/embed, providerNameSlug is set when WordPress identifies the
        // provider. For legacy core-embed/youtube the block name is enough.
        if ($name === 'core/embed') {
            $provider = $block['attrs']['providerNameSlug'] ?? '';
            if ($provider !== 'youtube' && $provider !== 'youtube-shorts') {
                return $blockContent;
            }
        }
        if (Consent::isAllowed(self::SERVICE_KEY)) {
            return $blockContent;
        }
        $url = $block['attrs']['url'] ?? '';
        $videoId = is_string($url) ? self::extractVideoId($url) : null;
        if ($videoId === null) {
            // Fallback: try to scrape an ID from the rendered HTML (some themes
            // resolve oEmbed without keeping the URL on the block).
            if (preg_match('#(?:youtube\.com/embed/|youtu\.be/|youtube-nocookie\.com/embed/)([a-zA-Z0-9_-]{11})#', $blockContent, $m)) {
                $videoId = $m[1];
            }
        }
        if ($videoId === null) {
            return $blockContent;
        }
        return self::renderPlaceholder($videoId);
    }

    /**
     * Accepts a bare 11-char YouTube video ID OR any of:
     *   https://www.youtube.com/watch?v=<id>
     *   https://www.youtube.com/embed/<id>
     *   https://www.youtube.com/shorts/<id>
     *   https://youtu.be/<id>
     *   https://www.youtube-nocookie.com/embed/<id>
     */
    public static function extractVideoId(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $input)) {
            return $input;
        }
        if (preg_match('#(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/)|youtube-nocookie\.com/embed/)([a-zA-Z0-9_-]{11})#', $input, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function renderIframe(string $videoId): string
    {
        $videoId = esc_attr($videoId);
        $title = esc_attr__('YouTube video player', 'lcmt-dev-consent');
        // Inline style: youtube.css is intentionally NOT loaded once consent is
        // granted (saves bytes for returning visitors), so the iframe must
        // carry its own sizing. aspect-ratio + height:auto keeps it 16:9 in
        // any wrapper, with or without an enforced parent aspect.
        $style = 'width:100%;aspect-ratio:16/9;height:auto;border:0;display:block';
        return '<iframe'
            . ' src="https://www.youtube-nocookie.com/embed/' . $videoId . '"'
            . ' title="' . $title . '"'
            . ' style="' . $style . '"'
            . ' frameborder="0"'
            . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"'
            . ' allowfullscreen'
            . ' loading="lazy"></iframe>';
    }

    public static function renderPlaceholder(string $videoId): string
    {
        $videoId = esc_attr($videoId);
        $title = esc_html(self::translatedString(
            'youtube.placeholder.title',
            'This content is hosted on YouTube'
        ));
        $body = esc_html(self::translatedString(
            'youtube.placeholder.body',
            'Loading it will set cookies from YouTube/Google. Accept to play the video.'
        ));
        $accept = esc_html(self::translatedString(
            'youtube.placeholder.accept',
            'Accept and play'
        ));

        return '<div class="lcmt-yt-placeholder" data-lcmt-youtube-id="' . $videoId . '" role="region" aria-label="' . esc_attr($title) . '">'
            . '<div class="lcmt-yt-placeholder__inner">'
            . '<p class="lcmt-yt-placeholder__title">' . $title . '</p>'
            . '<p class="lcmt-yt-placeholder__body">' . $body . '</p>'
            . '<div class="lcmt-yt-placeholder__actions">'
            . '<button type="button" class="lcmt-yt-placeholder__accept">' . $accept . '</button>'
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

    private function isYoutubeUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return false;
        }
        $host = strtolower($host);
        return $host === 'youtu.be'
            || $host === 'youtube.com'
            || $host === 'www.youtube.com'
            || $host === 'm.youtube.com'
            || $host === 'www.youtube-nocookie.com'
            || $host === 'youtube-nocookie.com';
    }

    /**
     * Run an English source string through the existing translation pipeline:
     *   1. lcmt_dev_consent_string filter (lets code override)
     *   2. WordPress translate() (.mo file in languages/)
     */
    private static function translatedString(string $name, string $default): string
    {
        $value = translate($default, 'lcmt-dev-consent');
        return (string) apply_filters('lcmt_dev_consent_string', $value, $name);
    }
}
