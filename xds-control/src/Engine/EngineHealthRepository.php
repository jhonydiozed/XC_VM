<?php

declare(strict_types=1);

namespace XdsControl\Engine;

use PDO;
use RuntimeException;

final class EngineHealthRepository
{
    public function __construct(private PDO $engine)
    {
    }

    public function inspect(): array
    {
        $database = (string) $this->engine->query('SELECT DATABASE()')->fetchColumn();
        if ($database === '') {
            throw new RuntimeException('The engine connection has no selected database.');
        }

        $tableCount = (int) $this->engine
            ->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')
            ->fetchColumn();

        $expected = ['streams', 'servers', 'users', 'bouquets'];
        $placeholders = implode(',', array_fill(0, count($expected), '?'));
        $statement = $this->engine->prepare(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})"
        );
        $statement->execute($expected);
        $present = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));

        $counts = [];
        foreach ($present as $table) {
            // Table names come from the fixed allow-list above, never from request input.
            $counts[$table] = (int) $this->engine->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        }

        return [
            'database' => $database,
            'mariadb_version' => (string) $this->engine->query('SELECT VERSION()')->fetchColumn(),
            'table_count' => $tableCount,
            'expected_tables' => [
                'present' => $present,
                'missing' => array_values(array_diff($expected, $present)),
            ],
            'record_counts' => $counts,
            'mode' => 'read_only',
        ];
    }
}
