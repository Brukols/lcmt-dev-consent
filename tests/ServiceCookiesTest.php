<?php

namespace LcmtDev\Consent\Tests;

use LcmtDev\Consent\Services\Service;

class ServiceCookiesTest extends TestCase
{
    public function test_cookies_defaults_to_empty_array(): void
    {
        $svc = new Service(['key' => 'x']);
        $this->assertSame([], $svc->cookies);
    }

    public function test_cookies_populated_from_args(): void
    {
        $rows = [['name' => '_x', 'purpose' => 'P', 'retention' => '1 day']];
        $svc = new Service(['key' => 'x', 'cookies' => $rows]);
        $this->assertSame($rows, $svc->cookies);
    }

    public function test_cookies_absent_from_client_config(): void
    {
        $svc = new Service(['key' => 'x', 'cookies' => [['name' => '_x']]]);
        $this->assertArrayNotHasKey('cookies', $svc->toClientConfig());
    }
}
