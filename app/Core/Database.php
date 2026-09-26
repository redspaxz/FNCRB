<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class Database
{
    /** @var array<string, PDO> */
    private static array $pdo = [];

    /**
     * 'default' serves business queries; 'audit' is a dedicated autocommit
     * connection so audit entries are chained and persisted independently of
     * any business transaction that may later roll back.
     */
    public static function pdo(string $name = 'default'): PDO
    {
        if (!isset(self::$pdo[$name])) {
            $db = Config::get('db');
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
            self::$pdo[$name] = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // true prepared statements (OWASP injection)
            ]);
            // align SQL NOW()/TIMESTAMP conversion with PHP's clock (lockouts, consent expiry, SLAs)
            self::$pdo[$name]->exec("SET time_zone = '" . date('P') . "'");
        }
        return self::$pdo[$name];
    }
}
