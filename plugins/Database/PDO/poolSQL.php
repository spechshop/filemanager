<?php

namespace plugins\dataSwoole;

use PDO;
use PDOException;
use plugins\Start\cache;

class poolSQL
{
    public PDO $pool;

    public function __construct()
    {
        
        $dataServer = cache::global()['interface']['mysqlServer'];
        $dsn = "mysql:dbname=" . $dataServer['database'] . ";host=" . $dataServer['host'] . ";port=" . $dataServer['port'] . ";";
        try {
            $this->pool = new PDO($dsn, $dataServer['username'], $dataServer['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $error) {
            //throw new PDOException($error->getMessage());
        }
    }

    public function registerAll(array $phones, string $creator, string $message): ?bool
    {
        $stmt = $this->pool->prepare("INSERT INTO `pendent-phones` (`phone`, `creator`, `message`) VALUES (?, ?, ?);");
        $this->pool->beginTransaction();
        foreach ($phones as $phone) $stmt->execute([$phone, $creator, $message]);
        $this->pool->commit();
        return true;
    }

    public function returnAll(string $table, int $limit): ?array
    {
        $stmt = $this->pool->prepare("SELECT * FROM `$table` LIMIT $limit;");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function deleteGroup(array $phones): ?bool
    {
        $stmt = $this->pool->prepare("DELETE FROM `pendent-phones` WHERE `phone` IN (?);");
        $this->pool->beginTransaction();
        foreach ($phones as $phone) $stmt->execute([$phone]);
        $this->pool->commit();
        return true;
    }
}