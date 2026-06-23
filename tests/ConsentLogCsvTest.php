<?php

namespace LcmtDev\Consent\Tests;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Log\ConsentLog;
use LcmtDev\Consent\Tests\Fakes\FakeWpdb;

class ConsentLogCsvTest extends TestCase
{
    public function test_export_csv_has_header_and_rows(): void
    {
        $log = new ConsentLog(new Settings(), new FakeWpdb());
        $csv = $log->exportCsv([
            [
                'id' => 1,
                'consent_id' => 'abc',
                'event' => 'accept_all',
                'choices' => '{"ga":true}',
                'policy_version' => 'deadbeef0001',
                'cookie_version' => '0',
                'created_at' => '2026-06-23 10:00:00',
            ],
        ]);

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertSame('id,consent_id,event,choices,policy_version,cookie_version,created_at', $lines[0]);
        $this->assertStringContainsString('1,abc,accept_all', $lines[1]);
        $this->assertStringContainsString('2026-06-23 10:00:00', $lines[1]);
    }

    public function test_export_csv_header_only_when_empty(): void
    {
        $log = new ConsentLog(new Settings(), new FakeWpdb());
        $csv = trim($log->exportCsv([]));
        $this->assertSame('id,consent_id,event,choices,policy_version,cookie_version,created_at', $csv);
    }
}
