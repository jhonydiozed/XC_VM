<?php

declare(strict_types=1);

namespace XdsControl\Database;

use PDO;
use PDOException;
use RuntimeException;

final class ConnectionManager
{
    private array $config;
    private ?PDO $engine = null;
    private ?PDO $panel = null;

    public function __construct(array $config)
    {
        $required = ['host', 'port', 'username', 'password', 'engine_database', 'panel_database', 'charset'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $config)) {
                throw new RuntimeException("Missing database configuration key: {$key}");
            }
        }

        $this->config = $config;
    }

    public function engine(): PDO
    {
        return $this->engine ??= $this->connect((string) $this->config['engine_database']);
    }

    public function panel(): PDO
    {
        return $this->panel ??= $this->connect((string) $this->config['panel_database']);
    }

    private function connect(string $database): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            (int) $this->config['port'],
            $database,
            $this->config['charset']
        );

        try {
            return new PDO($dsn, (string) $this->config['username'], (string) $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException(
                sprintf('Unable to connect to database %s: %s', $database, $exception->getMessage()),
                0,
                $exception
            );
        }
    }
}
