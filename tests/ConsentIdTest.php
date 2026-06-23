<?php

namespace LcmtDev\Consent\Tests;

use LcmtDev\Consent\Consent;

class ConsentIdTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE['cookieConsent']);
        parent::tearDown();
    }

    public function test_returns_cid_when_present(): void
    {
        $_COOKIE['cookieConsent'] = '!googleanalytics=true!cid=abc-123';
        $this->assertSame('abc-123', Consent::getConsentId('cookieConsent'));
    }

    public function test_returns_null_when_absent(): void
    {
        $_COOKIE['cookieConsent'] = '!googleanalytics=true';
        $this->assertNull(Consent::getConsentId('cookieConsent'));
    }

    public function test_returns_null_when_no_cookie(): void
    {
        $this->assertNull(Consent::getConsentId('cookieConsent'));
    }
}
