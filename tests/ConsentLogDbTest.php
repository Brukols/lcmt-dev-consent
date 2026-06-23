<?php

namespace LcmtDev\Consent\Tests;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Log\ConsentLog;
use LcmtDev\Consent\Tests\Fakes\FakeWpdb;

class ConsentLogDbTest extends TestCase
{
    private function makeLog(FakeWpdb $db): ConsentLog
    {
        return new ConsentLog(new Settings(), $db);
    }

    public function test_table_name_uses_prefix(): void
    {
        $db = new FakeWpdb();
        $this->assertSame('wp_lcmt_consent_log', $this->makeLog($db)->tableName());
    }

    public function test_insert_writes_expected_columns_and_returns_id(): void
    {
        $db = new FakeWpdb();
        $id = $this->makeLog($db)->insert([
            'consent_id' => 'abc',
            'event' => 'accept_all',
            'choices' => '{"googleanalytics":true}',
            'policy_version' => 'deadbeef0001',
            'cookie_version' => '0',
            'created_at' => '2026-06-23 10:00:00',
        ]);

        $this->assertSame(1, $id);
        $this->assertCount(1, $db->inserts);
        $data = $db->inserts[0]['data'];
        $this->assertSame('abc', $data['consent_id']);
        $this->assertSame('accept_all', $data['event']);
        $this->assertSame('{"googleanalytics":true}', $data['choices']);
        $this->assertSame('2026-06-23 10:00:00', $data['created_at']);
    }

    public function test_insert_defaults_created_at_when_missing(): void
    {
        $db = new FakeWpdb();
        $this->makeLog($db)->insert(['consent_id' => 'x', 'event' => 'custom']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $db->inserts[0]['data']['created_at']
        );
    }

    public function test_build_conditions_empty(): void
    {
        $db = new FakeWpdb();
        $res = $this->makeLog($db)->buildConditions([]);
        $this->assertSame('', $res['where']);
        $this->assertSame([], $res['args']);
    }

    public function test_build_conditions_filters(): void
    {
        $db = new FakeWpdb();
        $res = $this->makeLog($db)->buildConditions([
            'consent_id' => 'abc',
            'event' => 'withdraw',
            'from' => '2026-01-01',
            'to' => '2026-12-31',
        ]);
        $this->assertStringContainsString('consent_id = %s', $res['where']);
        $this->assertStringContainsString('event = %s', $res['where']);
        $this->assertStringContainsString('created_at >= %s', $res['where']);
        $this->assertStringContainsString('created_at <= %s', $res['where']);
        $this->assertSame(['abc', 'withdraw', '2026-01-01 00:00:00', '2026-12-31 23:59:59'], $res['args']);
    }

    public function test_purge_runs_a_delete_and_returns_count(): void
    {
        $db = new FakeWpdb();
        $db->deleteReturn = 7;
        $deleted = $this->makeLog($db)->purgeOlderThan(36);
        $this->assertSame(7, $deleted);
        $this->assertCount(1, $db->queries);
        $this->assertStringContainsString('DELETE FROM wp_lcmt_consent_log', $db->queries[0]);
        $this->assertStringContainsString('created_at <', $db->queries[0]);
    }
}
