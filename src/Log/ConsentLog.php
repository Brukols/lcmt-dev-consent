<?php

namespace LcmtDev\Consent\Log;

use LcmtDev\Consent\Admin\Settings;
use LcmtDev\Consent\Services\ServiceRegistry;

class ConsentLog
{
    public const DB_VERSION = '1';
    public const DB_VERSION_OPTION = 'lcmt_dev_consent_db_version';
    public const TABLE_SUFFIX = 'lcmt_consent_log';

    /** @var \wpdb */
    private $wpdb;
    private Settings $settings;

    /**
     * @param \wpdb|null $wpdb Injectable for tests; falls back to the global.
     */
    public function __construct(Settings $settings, $wpdb = null)
    {
        $this->settings = $settings;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
    }

    public static function hashConfig(array $config): string
    {
        return substr(sha1((string) wp_json_encode($config)), 0, 12);
    }

    public function policyVersion(Settings $settings, ServiceRegistry $registry): string
    {
        $services = [];
        foreach ($registry->all() as $svc) {
            $services[] = ['key' => $svc->key, 'name' => $svc->name, 'category' => $svc->category];
        }

        return self::hashConfig([
            'services' => $services,
            'texts' => (array) $settings->get('texts', []),
            'categories' => (array) $settings->get('categories', []),
            'privacy_url' => (string) $settings->get('privacy_url', ''),
        ]);
    }

    public function tableName(): string
    {
        return $this->wpdb->prefix . self::TABLE_SUFFIX;
    }

    public function insert(array $record): int
    {
        $row = array_merge([
            'consent_id' => '',
            'event' => '',
            'choices' => '',
            'policy_version' => '',
            'cookie_version' => '',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], $record);

        $this->wpdb->insert(
            $this->tableName(),
            [
                'consent_id' => (string) $row['consent_id'],
                'event' => (string) $row['event'],
                'choices' => (string) $row['choices'],
                'policy_version' => (string) $row['policy_version'],
                'cookie_version' => (string) $row['cookie_version'],
                'created_at' => (string) $row['created_at'],
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        return (int) $this->wpdb->insert_id;
    }

    /**
     * @return array{where:string,args:array}
     */
    public function buildConditions(array $filters): array
    {
        $clauses = [];
        $args = [];

        if (!empty($filters['consent_id'])) {
            $clauses[] = 'consent_id = %s';
            $args[] = (string) $filters['consent_id'];
        }
        if (!empty($filters['event'])) {
            $clauses[] = 'event = %s';
            $args[] = (string) $filters['event'];
        }
        if (!empty($filters['from'])) {
            $clauses[] = 'created_at >= %s';
            $args[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $clauses[] = 'created_at <= %s';
            $args[] = $filters['to'] . ' 23:59:59';
        }

        return [
            'where' => $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '',
            'args' => $args,
        ];
    }

    public function purgeOlderThan(int $months): int
    {
        $months = max(1, $months);
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . $months . ' months', strtotime(gmdate('Y-m-d H:i:s'))));
        $sql = $this->wpdb->prepare(
            'DELETE FROM ' . $this->tableName() . ' WHERE created_at < %s',
            $cutoff
        );
        return (int) $this->wpdb->query($sql);
    }

    /**
     * @return array{rows:array,total:int}
     */
    public function query(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $perPage = max(1, min(500, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $cond = $this->buildConditions($filters);
        $table = $this->tableName();

        $countSql = 'SELECT COUNT(*) FROM ' . $table . ' ' . $cond['where'];
        $countSql = $cond['args']
            ? $this->wpdb->prepare($countSql, $cond['args'])
            : $countSql;
        $total = (int) $this->wpdb->get_var($countSql);

        $rowsSql = 'SELECT * FROM ' . $table . ' ' . $cond['where']
            . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $rowsSql = $this->wpdb->prepare($rowsSql, array_merge($cond['args'], [$perPage, $offset]));
        $rows = $this->wpdb->get_results($rowsSql, ARRAY_A);

        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    public function exportCsv(array $rows): string
    {
        $columns = ['id', 'consent_id', 'event', 'choices', 'policy_version', 'cookie_version', 'created_at'];
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $columns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $line[] = $row[$col] ?? '';
            }
            fputcsv($fh, $line);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    public function createTable(): void
    {
        $table = $this->tableName();
        $charset = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            consent_id CHAR(36) NOT NULL DEFAULT '',
            event VARCHAR(20) NOT NULL DEFAULT '',
            choices TEXT NULL,
            policy_version VARCHAR(40) NOT NULL DEFAULT '',
            cookie_version VARCHAR(40) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY consent_id (consent_id),
            KEY event (event),
            KEY policy_version (policy_version),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    public function maybeUpgrade(): void
    {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            $this->createTable();
        }
    }
}
