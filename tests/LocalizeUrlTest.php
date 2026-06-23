<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Admin\Translations;

class LocalizeUrlTest extends TestCase
{
    private function t(): Translations
    {
        Functions\when('get_option')->justReturn([]);
        return new Translations(new Settings());
    }

    public function test_empty_url_returns_empty(): void
    {
        $this->assertSame('', $this->t()->localizeUrl(''));
    }

    public function test_returns_url_unchanged_without_polylang(): void
    {
        // pll_get_post is not defined → no Polylang → unchanged.
        $this->assertSame('https://site.test/privacy', $this->t()->localizeUrl('https://site.test/privacy'));
    }

    public function test_external_url_not_a_page_returns_unchanged(): void
    {
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('url_to_postid')->justReturn(0); // not a local page
        $this->assertSame('https://external.test/policy', $this->t()->localizeUrl('https://external.test/policy'));
    }

    public function test_resolves_translated_page_permalink(): void
    {
        Functions\when('url_to_postid')->justReturn(10);
        Functions\when('pll_current_language')->justReturn('en');
        Functions\when('pll_get_post')->alias(fn($id, $lang = '') => ($id === 10 && $lang === 'en') ? 20 : 0);
        Functions\when('get_permalink')->alias(fn($id) => $id === 20 ? 'https://site.test/en/privacy' : '');

        $this->assertSame('https://site.test/en/privacy', $this->t()->localizeUrl('https://site.test/confidentialite'));
    }

    public function test_returns_permalink_even_when_resolved_id_equals_post_id(): void
    {
        // On a translated front page Polylang maps url_to_postid() straight to the
        // current-language post, so translation == postId. We must still return
        // that post's permalink, not the (other-language) stored URL.
        Functions\when('url_to_postid')->justReturn(736);
        Functions\when('pll_current_language')->justReturn('en');
        Functions\when('pll_get_post')->alias(fn($id, $lang = '') => $id === 736 ? 736 : 0);
        Functions\when('get_permalink')->alias(fn($id) => $id === 736 ? 'https://site.test/en/privacy-policy/' : '');

        $this->assertSame(
            'https://site.test/en/privacy-policy/',
            $this->t()->localizeUrl('https://site.test/politique-de-confidentialite/')
        );
    }

    public function test_no_translation_falls_back_to_resolved_page_permalink(): void
    {
        Functions\when('url_to_postid')->justReturn(10);
        Functions\when('pll_current_language')->justReturn('de');
        Functions\when('pll_get_post')->justReturn(0); // no German translation
        Functions\when('get_permalink')->alias(fn($id) => $id === 10 ? 'https://site.test/confidentialite' : '');
        $this->assertSame('https://site.test/confidentialite', $this->t()->localizeUrl('https://site.test/confidentialite'));
    }
}
