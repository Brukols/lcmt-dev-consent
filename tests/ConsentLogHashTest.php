<?php

namespace LcmtDev\Consent\Tests;

use Brain\Monkey\Functions;
use LcmtDev\Consent\Log\ConsentLog;

class ConsentLogHashTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_json_encode')->alias('json_encode');
    }

    public function test_hash_is_deterministic_and_short(): void
    {
        $a = ConsentLog::hashConfig(['x' => 1, 'y' => 2]);
        $b = ConsentLog::hashConfig(['x' => 1, 'y' => 2]);

        $this->assertSame($a, $b);
        $this->assertSame(12, strlen($a));
    }

    public function test_hash_changes_when_config_changes(): void
    {
        $a = ConsentLog::hashConfig(['x' => 1]);
        $b = ConsentLog::hashConfig(['x' => 2]);

        $this->assertNotSame($a, $b);
    }
}
