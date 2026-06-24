<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Frontend\GoogleMapsEmbed;

class GoogleMapsEmbedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr__')->returnArg(1);
        Functions\when('translate')->returnArg(1);
        Functions\when('apply_filters')->returnArg(2);
        unset($_COOKIE['cookieConsent']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['cookieConsent']);
        parent::tearDown();
    }

    private function mapsIframe(): string
    {
        return '<iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12" '
            . 'width="600" height="450" style="border:0;" allowfullscreen="" '
            . 'loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';
    }

    public function test_is_maps_url_recognizes_embed_urls(): void
    {
        $this->assertTrue(GoogleMapsEmbed::isMapsUrl('https://www.google.com/maps/embed?pb=x'));
        $this->assertTrue(GoogleMapsEmbed::isMapsUrl('https://maps.google.com/maps?q=Paris&output=embed'));
        $this->assertTrue(GoogleMapsEmbed::isMapsUrl('https://www.google.fr/maps/embed?pb=x'));
        $this->assertTrue(GoogleMapsEmbed::isMapsUrl('https://www.google.com/maps/d/embed?mid=abc'));
    }

    public function test_is_maps_url_rejects_non_maps(): void
    {
        $this->assertFalse(GoogleMapsEmbed::isMapsUrl('https://www.youtube.com/embed/abcdefghijk'));
        $this->assertFalse(GoogleMapsEmbed::isMapsUrl('https://www.google.com/recaptcha/api.js'));
        $this->assertFalse(GoogleMapsEmbed::isMapsUrl('https://example.com/maps/embed'));
        $this->assertFalse(GoogleMapsEmbed::isMapsUrl('not a url'));
    }

    public function test_render_placeholder_carries_src_and_accept_button(): void
    {
        $html = GoogleMapsEmbed::renderPlaceholder('https://www.google.com/maps/embed?pb=x', 450);
        $this->assertStringContainsString('lcmt-maps-placeholder', $html);
        $this->assertStringContainsString('data-lcmt-maps-src="https://www.google.com/maps/embed?pb=x"', $html);
        $this->assertStringContainsString('data-lcmt-maps-height="450"', $html);
        $this->assertStringContainsString('lcmt-maps-placeholder__accept', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function test_filter_replaces_maps_iframe_with_placeholder_when_pending(): void
    {
        Functions\when('get_option')->justReturn(['services' => ['googlemaps' => ['enabled' => true]]]);
        $out = (new GoogleMapsEmbed())->filterContent('<p>before</p>' . $this->mapsIframe() . '<p>after</p>');
        $this->assertStringContainsString('lcmt-maps-placeholder', $out);
        $this->assertStringContainsString('https://www.google.com/maps/embed?pb=!1m18!1m12', $out);
        $this->assertStringNotContainsString('<iframe', $out);
        // Surrounding content untouched.
        $this->assertStringContainsString('<p>before</p>', $out);
        $this->assertStringContainsString('<p>after</p>', $out);
    }

    public function test_filter_preserves_height_attribute(): void
    {
        Functions\when('get_option')->justReturn(['services' => ['googlemaps' => ['enabled' => true]]]);
        $out = (new GoogleMapsEmbed())->filterContent($this->mapsIframe());
        $this->assertStringContainsString('data-lcmt-maps-height="450"', $out);
    }

    public function test_filter_passthrough_when_service_disabled(): void
    {
        Functions\when('get_option')->justReturn(['services' => ['googlemaps' => ['enabled' => false]]]);
        $in = $this->mapsIframe();
        $this->assertSame($in, (new GoogleMapsEmbed())->filterContent($in));
    }

    public function test_filter_passthrough_when_already_accepted(): void
    {
        Functions\when('get_option')->justReturn(['services' => ['googlemaps' => ['enabled' => true]]]);
        $_COOKIE['cookieConsent'] = '!googlemaps=true';
        $in = $this->mapsIframe();
        $this->assertSame($in, (new GoogleMapsEmbed())->filterContent($in));
    }

    public function test_filter_ignores_non_maps_iframe(): void
    {
        Functions\when('get_option')->justReturn(['services' => ['googlemaps' => ['enabled' => true]]]);
        $yt = '<iframe src="https://www.youtube.com/embed/abcdefghijk" width="560" height="315"></iframe>';
        $this->assertSame($yt, (new GoogleMapsEmbed())->filterContent($yt));
        $this->assertStringNotContainsString('lcmt-maps-placeholder', (new GoogleMapsEmbed())->filterContent($yt));
    }
}
