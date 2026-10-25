<?php

namespace Tests\Concerns;

use PDO;
use PDOException;

/**
 * Where the opt-in MySQL tests find their server: the same DB_HOST / DB_PORT /
 * DB_USERNAME / DB_PASSWORD the application would use, read from the environment.
 * A plain `PDO` to the server (no database), enough to ask whether it is there
 * and to create a scratch database on it.
 */
trait ConnectsToMysqlServer
{
    protected function host(): string
    {
        return (string) env('DB_HOST', '127.0.0.1');
    }

    protected function port(): string
    {
        return (string) env('DB_PORT', '3306');
    }

    protected function serverPdo(): PDO
    {
        return new PDO(
            'mysql:host='.$this->host().';port='.$this->port(),
            (string) env('DB_USERNAME', 'root'),
            (string) env('DB_PASSWORD', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    protected function mysqlIsListening(): bool
    {
        try {
            $this->serverPdo();

            return true;
        } catch (PDOException) {
            return false;
        }
    }
}
