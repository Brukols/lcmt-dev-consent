<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Log\ConsentLog;
use LcmtDev\Consent\Log\RestController;
use LcmtDev\Consent\Services\ServiceRegistry;
use LcmtDev\Consent\Tests\Fakes\FakeWpdb;

class RestControllerTest extends TestCase
{
    private function make(): RestController
    {
        $settings = new Settings();
        $registry = new ServiceRegistry($settings);
        $log = new ConsentLog($settings, new FakeWpdb());
        return new RestController($settings, $registry, $log);
    }

    public function test_valid_events(): void
    {
        $c = $this->make();
        foreach (['accept_all', 'reject_all', 'custom', 'withdraw'] as $e) {
            $this->assertTrue($c->isValidEvent($e));
        }
        $this->assertFalse($c->isValidEvent('hack'));
        $this->assertFalse($c->isValidEvent(''));
    }

    public function test_rate_limit_blocks_after_threshold(): void
    {
        $counter = 0;
        Functions\when('get_transient')->alias(function () use (&$counter) {
            return $counter ?: false;
        });
        Functions\when('set_transient')->alias(function ($k, $v) use (&$counter) {
            $counter = $v;
            return true;
        });

        $c = $this->make();
        $blocked = false;
        for ($i = 0; $i < 35; $i++) {
            $blocked = $c->rateLimitExceeded('203.0.113.9');
        }
        $this->assertTrue($blocked);
    }

    public function test_rate_limit_allows_first_request(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);

        $c = $this->make();
        $this->assertFalse($c->rateLimitExceeded('203.0.113.9'));
    }
}
