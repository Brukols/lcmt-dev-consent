<?php

namespace LcmtDev\Consent\Tests\Fakes;

class FakeWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;

    /** @var array<int,array{table:string,data:array,formats:array}> */
    public array $inserts = [];
    /** @var array<int,string> */
    public array $queries = [];
    public int $deleteReturn = 0;

    public function insert($table, $data, $formats)
    {
        $this->inserts[] = ['table' => $table, 'data' => $data, 'formats' => $formats];
        $this->insert_id = count($this->inserts);
        return 1;
    }

    public function prepare($query, ...$args)
    {
        // Naive interpolation good enough for assertions in tests.
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        foreach ($args as $a) {
            $replacement = is_int($a) ? (string) $a : "'" . $a . "'";
            $query = preg_replace('/%[ds]/', $replacement, $query, 1);
        }
        return $query;
    }

    public function query($sql)
    {
        $this->queries[] = $sql;
        return $this->deleteReturn;
    }
}
